<?php

namespace App\Services;

use App\Enums\TripStatus;
use App\Models\FuelRecord;
use App\Models\Report;
use App\Models\Trip;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Figures for Reports → Overview and generated reports, computed from live records:
 *
 * - Distance: odometer readings on fuel records. Each fill-up adds the kilometres since the same
 *   vehicle's previous reading (dated on the later fill-up); a reading that goes backwards is ignored.
 *   Trip distance_km is a planned figure and is rarely filled in, so it is not used. Null when no
 *   fill-up in the period has an earlier reading to compare with.
 * - Fuel: liters (and cost) entered in fuel records in the period, i.e. fuel bought, not burned.
 * - Trips: trips departing in the period.
 * - On-time rate: completed trips that arrived in the period, share with actual_arrival_at at or before
 *   estimated_arrival_at. Null when no trip was completed.
 * - Utilization rate (per vehicle): days in the period on which the vehicle was on a non-cancelled trip
 *   (departure until actual, else estimated, arrival) ÷ days in the period.
 *
 * - Fuel efficiency (Reports → Fuel Reports): kilometres of the odometer legs ÷ liters bought at the fill-ups
 *   that close them (full-tank method). Null when no leg is available.
 *
 * Periods are whole days in the app timezone. The comparison period is the same number of days
 * immediately before.
 */
class ReportService
{
    /**
     * @param  array{vehicle_id?: int|null, driver_id?: int|null}  $filters
     */
    public function overview(string $from, string $to, array $filters = []): array
    {
        [$start, $end] = $this->period($from, $to);
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        [$prevStart, $prevEnd] = [$start->subDays($days), $start->subSecond()];

        $current = $this->totals($start, $end, $filters);
        $previous = $this->totals($prevStart, $prevEnd, $filters);

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $prevStart->toDateString(), 'to' => $prevEnd->toDateString()],
            'stats' => collect(['distance_km', 'fuel_liters', 'fuel_cost', 'total_trips', 'on_time_rate'])
                ->mapWithKeys(fn ($key) => [$key => ['value' => $current[$key], 'previous' => $previous[$key]]]),
            'on_time' => ['completed' => $current['completed'], 'on_time' => $current['on_time']],
            'daily' => $this->daily($start, $end, $current['distance_by_day'], $filters),
            'trip_status' => $this->tripStatus($start, $end, $filters),
        ];
    }

    /** Payload for a generated report: summary tiles, per-vehicle utilization and trip status. */
    public function preview(Report $report): array
    {
        $filters = $report->filters ?? [];
        [$start, $end] = $this->period($report->period_from->toDateString(), $report->period_to->toDateString());
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;

        $trips = $this->trips($start, $end, $filters)->where('status', '!=', TripStatus::Cancelled)
            ->get(['id', 'vehicle_id', 'departure_at', 'estimated_arrival_at', 'actual_arrival_at']);
        $distances = $this->odometerDistances($start, $end, $filters);

        // With a driver filter, list only the vehicles that driver used in the period.
        $vehicles = Vehicle::query()
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['driver_id'] ?? null, fn (Builder $q) => $q->whereIn('id', $trips->pluck('vehicle_id')->merge($distances->pluck('vehicle_id'))->unique()))
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'vehicle_type', 'make', 'model']);

        $rows = $vehicles->map(function (Vehicle $vehicle) use ($trips, $distances, $start, $end, $days) {
            $own = $trips->where('vehicle_id', $vehicle->id);
            $legs = $distances->where('vehicle_id', $vehicle->id);
            $active = $this->activeDays($own, $start, $end);

            return [
                'id' => $vehicle->id,
                'plate_number' => $vehicle->plate_number,
                'vehicle_type' => $vehicle->vehicle_type?->value,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'distance_km' => $legs->isEmpty() ? null : round($legs->sum('km'), 1),
                'trips' => $own->count(),
                'active_days' => $active,
                'utilization_rate' => round($active / $days * 100, 1),
            ];
        })->values();

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days],
            'summary' => [
                'total_vehicles' => $rows->count(),
                'active_vehicles' => $rows->where('trips', '>', 0)->count(),
                'total_distance_km' => $distances->isEmpty() ? null : round($distances->sum('km'), 1),
                'total_trips' => $this->trips($start, $end, $filters)->count(),
            ],
            'vehicles' => $rows,
            'trip_status' => $this->tripStatus($start, $end, $filters),
        ];
    }

    /**
     * Reports → Fuel Reports: stat cards with the previous period, daily liters/cost, a breakdown by the chosen
     * grouping, fuel types, stations and the routes of trips that have fuel records (for the route filter).
     *
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, fuel_type?: string|null, payment_method?: string|null, station?: string|null, route_origin?: string|null, route_destination?: string|null}  $filters
     */
    public function fuelReport(string $from, string $to, array $filters = [], string $groupBy = 'vehicle'): array
    {
        [$start, $end] = $this->period($from, $to);
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        [$prevStart, $prevEnd] = [$start->subDays($days), $start->subSecond()];

        $current = $this->fuelTotals($start, $end, $filters);
        $previous = $this->fuelTotals($prevStart, $prevEnd, $filters);
        $records = $this->fuel($start, $end, $filters)->with(['vehicle:id,plate_number', 'driver:id,full_name'])
            ->get(['id', 'vehicle_id', 'driver_id', 'fueled_at', 'fuel_type', 'liters', 'total_cost', 'station']);
        $sum = fn (Collection $rows) => [
            'liters' => round((float) $rows->sum('liters'), 2),
            'cost' => round((float) $rows->sum('total_cost'), 2),
            'transactions' => $rows->count(),
        ];

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $prevStart->toDateString(), 'to' => $prevEnd->toDateString()],
            'stats' => collect(['fuel_liters', 'fuel_cost', 'efficiency_km_per_liter', 'distance_km', 'transactions'])
                ->mapWithKeys(fn ($key) => [$key => ['value' => $current[$key], 'previous' => $previous[$key]]]),
            'daily' => collect($this->daily($start, $end, collect(), $filters))
                ->map(fn ($day) => ['date' => $day['date'], 'fuel_liters' => $day['fuel_liters'], 'fuel_cost' => $day['fuel_cost']])->all(),
            'group_by' => $groupBy,
            'groups' => $this->fuelGroups($records, $groupBy, $start, $end)
                ->map(fn (Collection $rows, $key) => ['key' => (string) $key, 'label' => $rows->first()->group_label, ...$sum($rows)])
                ->values()->all(),
            'fuel_types' => $records->groupBy(fn (FuelRecord $r) => $r->fuel_type->value)
                ->map(fn (Collection $rows, $key) => ['key' => $key, 'label' => $rows->first()->fuel_type->label(), ...$sum($rows)])
                ->sortByDesc('liters')->values()->all(),
            'stations' => $records->filter(fn (FuelRecord $r) => trim((string) $r->station) !== '')->groupBy('station')
                ->map(fn (Collection $rows, $key) => ['key' => $key, 'label' => $key, ...$sum($rows)])
                ->sortByDesc('cost')->values()->all(),
            'routes' => Trip::query()->whereIn('id', FuelRecord::query()->whereNotNull('trip_id')->select('trip_id'))
                ->select(['origin', 'destination'])->distinct()->orderBy('origin')->orderBy('destination')->limit(200)->get()
                ->map(fn (Trip $t) => ['origin' => $t->origin, 'destination' => $t->destination])->all(),
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(string $from, string $to): array
    {
        return [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()];
    }

    private function totals(CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        $fuel = $this->fuel($start, $end, $filters);
        $distances = $this->odometerDistances($start, $end, $filters);
        $completed = $this->filtered(Trip::query(), $filters)->where('status', TripStatus::Completed)->whereBetween('actual_arrival_at', [$start, $end]);
        $completedCount = (clone $completed)->count();
        $onTime = (clone $completed)->whereColumn('actual_arrival_at', '<=', 'estimated_arrival_at')->count();

        return [
            'distance_km' => $distances->isEmpty() ? null : round($distances->sum('km'), 1),
            'distance_by_day' => $distances->groupBy('day')->map(fn (Collection $legs) => $legs->sum('km')),
            'fuel_liters' => round((float) (clone $fuel)->sum('liters'), 2),
            'fuel_cost' => round((float) (clone $fuel)->sum('total_cost'), 2),
            'total_trips' => $this->trips($start, $end, $filters)->count(),
            'completed' => $completedCount,
            'on_time' => $onTime,
            'on_time_rate' => $completedCount ? round($onTime / $completedCount * 100, 1) : null,
        ];
    }

    private function fuelTotals(CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        $totals = $this->fuel($start, $end, $filters)->toBase()
            ->selectRaw('count(*) as entries, coalesce(sum(liters), 0) as liters, coalesce(sum(total_cost), 0) as cost')->first();
        $legs = $this->odometerDistances($start, $end, $filters);
        $legLiters = (float) $legs->sum('liters');

        return [
            'fuel_liters' => (int) $totals->entries ? round((float) $totals->liters, 2) : null,
            'fuel_cost' => (int) $totals->entries ? round((float) $totals->cost, 2) : null,
            'transactions' => (int) $totals->entries,
            'distance_km' => $legs->isEmpty() ? null : round($legs->sum('km'), 1),
            'efficiency_km_per_liter' => $legs->isEmpty() || $legLiters <= 0 ? null : round($legs->sum('km') / $legLiters, 2),
        ];
    }

    /**
     * Fuel records grouped for the "Group By" charts; each record carries its group_label.
     * Dates are grouped in 7-day blocks from the start of the period.
     *
     * @return Collection<string, Collection<int, FuelRecord>>
     */
    private function fuelGroups(Collection $records, string $groupBy, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        foreach ($records as $record) {
            [$record->group_key, $record->group_label] = match ($groupBy) {
                'driver' => [(string) ($record->driver_id ?? 'none'), $record->driver?->full_name ?? 'No driver'],
                'fuel_type' => [$record->fuel_type->value, $record->fuel_type->label()],
                'date' => (function () use ($record, $start, $end) {
                    $block = intdiv((int) floor($start->diffInDays($record->fueled_at)), 7);
                    $from = $start->addDays($block * 7);
                    $to = $from->addDays(6)->min($end->startOfDay());

                    return [$from->toDateString(), $from->isSameDay($to) ? $from->format('M j') : $from->format('M j').' – '.$to->format($from->month === $to->month ? 'j' : 'M j')];
                })(),
                default => [(string) $record->vehicle_id, $record->vehicle?->plate_number ?? 'Unknown vehicle'],
            };
        }
        $groups = $records->groupBy('group_key');

        return $groupBy === 'date' ? $groups->sortKeys() : $groups->sortByDesc(fn (Collection $rows) => $rows->sum('liters'));
    }

    /** One row per day: distance (null when no odometer data at all in the period), fuel liters and cost. */
    private function daily(CarbonImmutable $start, CarbonImmutable $end, Collection $distanceByDay, array $filters): array
    {
        $fuel = $this->fuel($start, $end, $filters)->get(['fueled_at', 'liters', 'total_cost'])
            ->groupBy(fn (FuelRecord $r) => $r->fueled_at->toDateString());
        $hasDistance = $distanceByDay->isNotEmpty();

        return collect(CarbonPeriod::create($start, '1 day', $end->startOfDay()))->map(function ($day) use ($fuel, $distanceByDay, $hasDistance) {
            $key = $day->toDateString();
            $records = $fuel->get($key, collect());

            return [
                'date' => $key,
                'distance_km' => $hasDistance ? round((float) $distanceByDay->get($key, 0), 1) : null,
                'fuel_liters' => round((float) $records->sum('liters'), 2),
                'fuel_cost' => round((float) $records->sum('total_cost'), 2),
            ];
        })->values()->all();
    }

    /**
     * Trips departing in the period, bucketed for the donut: Delivered = completed; In Transit = dispatched or
     * in progress and not yet past the estimated arrival; Delayed = dispatched or in progress past it;
     * Pending = scheduled; Cancelled.
     */
    private function tripStatus(CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        $count = fn (TripStatus ...$statuses) => $this->trips($start, $end, $filters)->whereIn('status', $statuses);
        $active = fn () => $count(...TripStatus::active());

        return [
            ['key' => 'delivered', 'label' => 'Delivered', 'count' => $count(TripStatus::Completed)->count()],
            ['key' => 'in_transit', 'label' => 'In Transit', 'count' => $active()->where('estimated_arrival_at', '>=', now())->count()],
            ['key' => 'pending', 'label' => 'Pending', 'count' => $count(TripStatus::Scheduled)->count()],
            ['key' => 'delayed', 'label' => 'Delayed', 'count' => $active()->where('estimated_arrival_at', '<', now())->count()],
            ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => $count(TripStatus::Cancelled)->count()],
        ];
    }

    /**
     * Kilometres driven between consecutive odometer readings, one entry per fill-up in the period that matches
     * the filters (the "closing" fill-up, which also carries the liters bought there).
     *
     * @return Collection<int, array{vehicle_id: int, day: string, km: float, liters: float}>
     */
    private function odometerDistances(CarbonImmutable $start, CarbonImmutable $end, array $filters): Collection
    {
        $closing = $this->fuel($start, $end, $filters)->whereNotNull('odometer_km')->pluck('vehicle_id', 'id');
        if ($closing->isEmpty()) {
            return collect();
        }

        // Every reading of those vehicles up to the end, so the first fill-up in the period has a predecessor.
        $readings = FuelRecord::whereIn('vehicle_id', $closing->unique()->values())->whereNotNull('odometer_km')->where('fueled_at', '<=', $end)
            ->orderBy('vehicle_id')->orderBy('fueled_at')->orderBy('id')
            ->get(['id', 'vehicle_id', 'fueled_at', 'odometer_km', 'liters']);

        return $readings->groupBy('vehicle_id')->flatMap(function (Collection $rows) use ($closing) {
            $legs = [];
            $previous = null;
            foreach ($rows as $row) {
                if ($previous !== null && $closing->has($row->id) && $row->odometer_km > $previous) {
                    $legs[] = ['vehicle_id' => $row->vehicle_id, 'day' => $row->fueled_at->toDateString(), 'km' => (float) ($row->odometer_km - $previous), 'liters' => (float) $row->liters];
                }
                $previous = max($previous ?? 0, $row->odometer_km);
            }

            return $legs;
        })->values();
    }

    /** Distinct days in [$start, $end] covered by any of the trips' time on the road. */
    private function activeDays(Collection $trips, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $days = [];
        foreach ($trips as $trip) {
            $from = max($trip->departure_at->toImmutable(), $start);
            $to = min(($trip->actual_arrival_at ?? $trip->estimated_arrival_at ?? $trip->departure_at)->toImmutable(), $end);
            for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
            }
        }

        return count($days);
    }

    private function trips(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return $this->filtered(Trip::query(), $filters)->whereBetween('departure_at', [$start, $end]);
    }

    /** Fuel records in the period; fuel_type, payment_method, station and route only come from Fuel Reports. */
    private function fuel(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return $this->filtered(FuelRecord::query(), $filters)->whereBetween('fueled_at', [$start, $end])
            ->when($filters['fuel_type'] ?? null, fn (Builder $q, $type) => $q->where('fuel_type', $type))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, $method) => $q->where('payment_method', $method))
            ->when($filters['station'] ?? null, fn (Builder $q, $station) => $q->where('station', $station))
            ->when($filters['route_origin'] ?? null, fn (Builder $q) => $q->whereHas('trip', fn (Builder $t) => $t
                ->where('origin', $filters['route_origin'])->where('destination', $filters['route_destination'] ?? '')));
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['driver_id'] ?? null, fn (Builder $q, $id) => $q->where('driver_id', $id));
    }
}
