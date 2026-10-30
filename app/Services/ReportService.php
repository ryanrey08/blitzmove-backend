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
     * Kilometres driven between consecutive odometer readings, one entry per fill-up in the period.
     *
     * @return Collection<int, array{vehicle_id: int, day: string, km: float}>
     */
    private function odometerDistances(CarbonImmutable $start, CarbonImmutable $end, array $filters): Collection
    {
        $vehicleIds = $this->fuel($start, $end, $filters)->whereNotNull('odometer_km')->distinct()->pluck('vehicle_id');
        if ($vehicleIds->isEmpty()) {
            return collect();
        }

        // Every reading of those vehicles up to the end, so the first fill-up in the period has a predecessor.
        $readings = FuelRecord::whereIn('vehicle_id', $vehicleIds)->whereNotNull('odometer_km')->where('fueled_at', '<=', $end)
            ->orderBy('vehicle_id')->orderBy('fueled_at')->orderBy('id')
            ->get(['vehicle_id', 'driver_id', 'fueled_at', 'odometer_km']);
        $driverId = $filters['driver_id'] ?? null;

        return $readings->groupBy('vehicle_id')->flatMap(function (Collection $rows) use ($start, $driverId) {
            $legs = [];
            $previous = null;
            foreach ($rows as $row) {
                if ($previous !== null && $row->fueled_at->gte($start) && $row->odometer_km > $previous && (! $driverId || $row->driver_id == $driverId)) {
                    $legs[] = ['vehicle_id' => $row->vehicle_id, 'day' => $row->fueled_at->toDateString(), 'km' => (float) ($row->odometer_km - $previous)];
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

    private function fuel(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return $this->filtered(FuelRecord::query(), $filters)->whereBetween('fueled_at', [$start, $end]);
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['driver_id'] ?? null, fn (Builder $q, $id) => $q->where('driver_id', $id));
    }
}
