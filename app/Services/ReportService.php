<?php

namespace App\Services;

use App\Enums\MaintenanceStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Models\Driver;
use App\Models\FuelRecord;
use App\Models\MaintenanceRecord;
use App\Models\Order;
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
 * - Trip duration / driving hours: actual departure until actual arrival of completed trips that have both.
 * - Trip distance (Trip Reports): the trip's own distance_km where one was entered; null when none was.
 * - Active hours (Utilization): time on non-cancelled trips (departure until actual, else estimated, arrival),
 *   overlapping trips counted once; idle hours are the rest of the period.
 * - On-time delivery: delivered orders with delivered_at at or before scheduled_delivery_at.
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
        $distances = $this->odometerDistances($start, $end, $filters);
        $rows = $this->utilizationRows($start, $end, $filters)->map(fn (array $row) => collect($row)
            ->only(['id', 'plate_number', 'vehicle_type', 'make', 'model', 'distance_km', 'trips', 'active_days', 'utilization_rate'])->all());

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

    /**
     * Reports → Trip Reports: stat cards with the previous period, trips per day and the status donut.
     * The trip rows come from /trips with the same filters.
     *
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, trip_status?: string|null}  $filters
     */
    public function tripReport(string $from, string $to, array $filters = []): array
    {
        [$start, $end, $days, $prevStart, $prevEnd] = $this->periods($from, $to);
        $current = $this->tripTotals($start, $end, $filters);
        $previous = $this->tripTotals($prevStart, $prevEnd, $filters);
        $trips = $this->trips($start, $end, $filters)->get(['id', 'status', 'departure_at'])
            ->groupBy(fn (Trip $t) => $t->departure_at->toDateString());

        return [
            ...$this->periodInfo($start, $end, $days, $prevStart, $prevEnd),
            'stats' => $this->compare($current, $previous, ['total_trips', 'completed', 'cancelled', 'on_time_rate', 'avg_duration_hours', 'distance_km']),
            'daily' => $this->days($start, $end)->map(fn (string $day) => [
                'date' => $day,
                'trips' => $trips->get($day, collect())->count(),
                'completed' => $trips->get($day, collect())->where('status', TripStatus::Completed)->count(),
            ])->all(),
            'trip_status' => $this->tripStatus($start, $end, $filters),
        ];
    }

    /**
     * Reports → Driver Reports: one row per driver with their trips departing in the period. With a vehicle filter,
     * only drivers who drove that vehicle are listed.
     *
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, driver_status?: string|null}  $filters
     */
    public function driverReport(string $from, string $to, array $filters = []): array
    {
        [$start, $end, $days, $prevStart, $prevEnd] = $this->periods($from, $to);
        $rows = $this->driverRows($start, $end, $filters);
        $previous = $this->driverRows($prevStart, $prevEnd, $filters);

        return [
            ...$this->periodInfo($start, $end, $days, $prevStart, $prevEnd),
            'stats' => $this->compare($this->driverTotals($rows), $this->driverTotals($previous), ['active_drivers', 'total_trips', 'completion_rate', 'on_time_rate', 'driving_hours']),
            'drivers' => $rows->values()->all(),
        ];
    }

    /**
     * Reports → Maintenance Reports: work scheduled in the period (the same set /maintenance-records lists for
     * from/to), broken down by status, service type and vehicle, plus open work due in the next 30 days or overdue.
     *
     * @param  array{vehicle_id?: int|null, category?: string|null, service_type?: string|null, status?: string|null}  $filters
     */
    public function maintenanceReport(string $from, string $to, array $filters = []): array
    {
        [$start, $end, $days, $prevStart, $prevEnd] = $this->periods($from, $to);
        $records = $this->maintenance($start, $end, $filters);
        $current = $this->maintenanceTotals($records);
        $previous = $this->maintenanceTotals($this->maintenance($prevStart, $prevEnd, $filters));
        $cost = fn (Collection $rows) => $rows->whereNotNull('actual_cost')->isEmpty() ? null : round((float) $rows->sum('actual_cost'), 2);

        $upcoming = MaintenanceRecord::query()->with('vehicle:id,plate_number')
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $q, $c) => $q->where('category', $c))
            ->when($filters['service_type'] ?? null, fn (Builder $q, $t) => $q->where('service_type', $t))
            ->whereIn('status', MaintenanceStatus::open())->whereDate('scheduled_date', '<=', today()->addDays(30))
            ->orderBy('scheduled_date')->orderBy('id')->limit(8)->get();

        return [
            ...$this->periodInfo($start, $end, $days, $prevStart, $prevEnd),
            'stats' => $this->compare($current, $previous, ['total', 'completed', 'overdue', 'total_cost']),
            'by_status' => $records->groupBy(fn (MaintenanceRecord $r) => $r->displayStatus())
                ->map(fn (Collection $rows, $key) => ['key' => $key, 'count' => $rows->count()])->values()->all(),
            'by_type' => $records->groupBy(fn (MaintenanceRecord $r) => $r->service_type->value)
                ->map(fn (Collection $rows, $key) => ['key' => $key, 'label' => $rows->first()->service_type->label(), 'count' => $rows->count(), 'cost' => $cost($rows)])
                ->sortByDesc('count')->values()->all(),
            'by_vehicle' => $records->groupBy('vehicle_id')
                ->map(fn (Collection $rows, $key) => ['key' => (string) $key, 'label' => $rows->first()->vehicle?->plate_number ?? 'Unknown vehicle', 'count' => $rows->count(), 'cost' => $cost($rows)])
                ->sortByDesc(fn ($row) => [$row['cost'] ?? 0, $row['count']])->values()->all(),
            'upcoming' => $upcoming->map(fn (MaintenanceRecord $r) => [
                'id' => $r->id,
                'reference_number' => $r->reference_number,
                'vehicle' => $r->vehicle?->plate_number,
                'service_type' => $r->service_type->value,
                'scheduled_date' => $r->scheduled_date->toDateString(),
                'display_status' => $r->displayStatus(),
            ])->all(),
        ];
    }

    /**
     * Reports → Utilization Reports: one row per vehicle (see utilizationRows), fleet averages and the number of
     * vehicles on the road each day.
     *
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, vehicle_type?: string|null, vehicle_status?: string|null}  $filters
     */
    public function utilizationReport(string $from, string $to, array $filters = []): array
    {
        [$start, $end, $days, $prevStart, $prevEnd] = $this->periods($from, $to);
        $rows = $this->utilizationRows($start, $end, $filters);
        $previous = $this->utilizationRows($prevStart, $prevEnd, $filters);

        $trips = $this->trips($start, $end, $filters)->where('status', '!=', TripStatus::Cancelled)
            ->whereIn('vehicle_id', $rows->pluck('id'))->get(['id', 'vehicle_id', 'departure_at', 'estimated_arrival_at', 'actual_arrival_at']);
        $onRoad = [];
        foreach ($trips->groupBy('vehicle_id') as $vehicleTrips) {
            foreach ($this->activeDayKeys($vehicleTrips, $start, $end) as $day) {
                $onRoad[$day] = ($onRoad[$day] ?? 0) + 1;
            }
        }

        return [
            ...$this->periodInfo($start, $end, $days, $prevStart, $prevEnd),
            'stats' => $this->compare($this->utilizationTotals($rows), $this->utilizationTotals($previous), ['utilization_rate', 'active_vehicles', 'idle_vehicles', 'active_hours', 'distance_km']),
            'daily' => $this->days($start, $end)->map(fn (string $day) => ['date' => $day, 'active_vehicles' => $onRoad[$day] ?? 0])->all(),
            'vehicles' => $rows->values()->all(),
        ];
    }

    /**
     * Reports → Delivery Reports: orders due for delivery in the period (scheduled_delivery_at), the same set
     * /orders lists for delivery_from/delivery_to.
     *
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, order_status?: string|null, customer?: string|null}  $filters
     */
    public function deliveryReport(string $from, string $to, array $filters = []): array
    {
        [$start, $end, $days, $prevStart, $prevEnd] = $this->periods($from, $to);
        $orders = $this->orders($start, $end, $filters)->get(['id', 'customer_name', 'status', 'scheduled_delivery_at', 'delivered_at']);
        $current = $this->deliveryTotals($orders);
        $previous = $this->deliveryTotals($this->orders($prevStart, $prevEnd, $filters)->get(['id', 'status', 'scheduled_delivery_at', 'delivered_at']));
        $byDay = $orders->groupBy(fn (Order $o) => $o->scheduled_delivery_at->toDateString());

        return [
            ...$this->periodInfo($start, $end, $days, $prevStart, $prevEnd),
            'stats' => $this->compare($current, $previous, ['total_orders', 'delivered', 'on_time_rate', 'delayed', 'cancelled']),
            'daily' => $this->days($start, $end)->map(fn (string $day) => [
                'date' => $day,
                'scheduled' => $byDay->get($day, collect())->count(),
                'delivered' => $byDay->get($day, collect())->where('status', OrderStatus::Delivered)->count(),
            ])->all(),
            'by_status' => collect(OrderStatus::cases())->map(fn (OrderStatus $status) => [
                'key' => $status->value, 'count' => $orders->where('status', $status)->count(),
            ])->all(),
            'customers' => $orders->groupBy('customer_name')
                ->map(fn (Collection $rows, $name) => ['key' => $name, 'label' => $name, 'count' => $rows->count(), 'delivered' => $rows->where('status', OrderStatus::Delivered)->count()])
                ->sortByDesc('count')->values()->take(10)->all(),
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable, int, CarbonImmutable, CarbonImmutable} */
    private function periods(string $from, string $to): array
    {
        [$start, $end] = $this->period($from, $to);
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;

        return [$start, $end, $days, $start->subDays($days), $start->subSecond()];
    }

    private function periodInfo(CarbonImmutable $start, CarbonImmutable $end, int $days, CarbonImmutable $prevStart, CarbonImmutable $prevEnd): array
    {
        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $prevStart->toDateString(), 'to' => $prevEnd->toDateString()],
        ];
    }

    /** {key: {value, previous}} for the stat cards. */
    private function compare(array $current, array $previous, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($key) => [$key => ['value' => $current[$key], 'previous' => $previous[$key]]])->all();
    }

    /** @return Collection<int, string> Y-m-d for every day of the period. */
    private function days(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return collect(CarbonPeriod::create($start, '1 day', $end->startOfDay()))->map(fn ($day) => $day->toDateString())->values();
    }

    private function tripTotals(CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        $trips = $this->trips($start, $end, $filters)
            ->get(['id', 'status', 'estimated_arrival_at', 'actual_departure_at', 'actual_arrival_at', 'distance_km']);
        $completed = $trips->where('status', TripStatus::Completed);
        $arrived = $completed->whereNotNull('actual_arrival_at');
        $hours = $this->durations($completed);
        $distances = $trips->whereNotNull('distance_km');

        return [
            'total_trips' => $trips->count(),
            'completed' => $completed->count(),
            'cancelled' => $trips->where('status', TripStatus::Cancelled)->count(),
            'on_time_rate' => $arrived->isEmpty() ? null : round($arrived->filter(fn (Trip $t) => $t->actual_arrival_at->lte($t->estimated_arrival_at))->count() / $arrived->count() * 100, 1),
            'avg_duration_hours' => $hours->isEmpty() ? null : round($hours->avg(), 1),
            'distance_km' => $distances->isEmpty() ? null : round((float) $distances->sum('distance_km'), 1),
        ];
    }

    /** Hours from actual departure to actual arrival, for trips that have both. */
    private function durations(Collection $trips): Collection
    {
        return $trips->filter(fn (Trip $t) => $t->actual_departure_at && $t->actual_arrival_at && $t->actual_arrival_at->gte($t->actual_departure_at))
            ->map(fn (Trip $t) => $t->actual_departure_at->diffInMinutes($t->actual_arrival_at) / 60)->values();
    }

    private function driverRows(CarbonImmutable $start, CarbonImmutable $end, array $filters): Collection
    {
        $trips = $this->trips($start, $end, $filters)
            ->get(['id', 'driver_id', 'status', 'estimated_arrival_at', 'actual_departure_at', 'actual_arrival_at'])->groupBy('driver_id');
        $legs = $this->odometerDistances($start, $end, $filters)->groupBy('driver_id');

        return Driver::query()
            ->when($filters['driver_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['driver_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q) => $q->whereIn('id', $trips->keys()->merge($legs->keys())->filter()))
            ->orderBy('full_name')->get(['id', 'employee_code', 'full_name', 'phone', 'status'])
            ->map(function (Driver $driver) use ($trips, $legs) {
                $own = $trips->get($driver->id, collect());
                $completed = $own->where('status', TripStatus::Completed);
                $arrived = $completed->whereNotNull('actual_arrival_at');
                $onTime = $arrived->filter(fn (Trip $t) => $t->actual_arrival_at->lte($t->estimated_arrival_at))->count();
                $assigned = $own->where('status', '!=', TripStatus::Cancelled)->count();
                $driverLegs = $legs->get($driver->id, collect());

                return [
                    'id' => $driver->id,
                    'employee_code' => $driver->employee_code,
                    'full_name' => $driver->full_name,
                    'phone' => $driver->phone,
                    'status' => $driver->status?->value,
                    'trips' => $own->count(),
                    'completed' => $completed->count(),
                    'pending' => $own->whereIn('status', TripStatus::blocking())->count(),
                    'cancelled' => $own->where('status', TripStatus::Cancelled)->count(),
                    'on_time' => $onTime,
                    'arrived' => $arrived->count(),
                    'completion_rate' => $assigned ? round($completed->count() / $assigned * 100, 1) : null,
                    'on_time_rate' => $arrived->isEmpty() ? null : round($onTime / $arrived->count() * 100, 1),
                    'driving_hours' => round($this->durations($completed)->sum(), 1),
                    'distance_km' => $driverLegs->isEmpty() ? null : round($driverLegs->sum('km'), 1),
                ];
            });
    }

    private function driverTotals(Collection $rows): array
    {
        $assigned = $rows->sum('trips') - $rows->sum('cancelled');

        return [
            'active_drivers' => $rows->where('trips', '>', 0)->count(),
            'total_trips' => $rows->sum('trips'),
            'completion_rate' => $assigned ? round($rows->sum('completed') / $assigned * 100, 1) : null,
            'on_time_rate' => $rows->sum('arrived') ? round($rows->sum('on_time') / $rows->sum('arrived') * 100, 1) : null,
            'driving_hours' => round($rows->sum('driving_hours'), 1),
        ];
    }

    /** Maintenance scheduled in the period; status is matched against the shown status (Due Soon, Overdue…). */
    private function maintenance(CarbonImmutable $start, CarbonImmutable $end, array $filters): Collection
    {
        return MaintenanceRecord::query()->with('vehicle:id,plate_number')
            ->whereDate('scheduled_date', '>=', $start->toDateString())->whereDate('scheduled_date', '<=', $end->toDateString())
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $q, $c) => $q->where('category', $c))
            ->when($filters['service_type'] ?? null, fn (Builder $q, $t) => $q->where('service_type', $t))
            ->get()
            ->when($filters['status'] ?? null, fn (Collection $rows, $status) => $rows->filter(fn (MaintenanceRecord $r) => $r->displayStatus() === $status))
            ->values();
    }

    private function maintenanceTotals(Collection $records): array
    {
        $costed = $records->whereNotNull('actual_cost');

        return [
            'total' => $records->count(),
            'completed' => $records->where('status', MaintenanceStatus::Completed)->count(),
            'overdue' => $records->filter(fn (MaintenanceRecord $r) => $r->displayStatus() === 'overdue')->count(),
            'total_cost' => $costed->isEmpty() ? null : round((float) $costed->sum('actual_cost'), 2),
        ];
    }

    /**
     * Per vehicle: trips departing in the period (not cancelled), days and hours on those trips, utilization
     * (days on a trip ÷ days in the period) and odometer distance. With a driver filter, only the vehicles that
     * driver used are listed.
     */
    private function utilizationRows(CarbonImmutable $start, CarbonImmutable $end, array $filters): Collection
    {
        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $trips = $this->trips($start, $end, $filters)->where('status', '!=', TripStatus::Cancelled)
            ->get(['id', 'vehicle_id', 'status', 'departure_at', 'estimated_arrival_at', 'actual_arrival_at']);
        $distances = $this->odometerDistances($start, $end, $filters);

        return Vehicle::query()
            ->when($filters['vehicle_id'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['vehicle_type'] ?? null, fn (Builder $q, $type) => $q->where('vehicle_type', $type))
            ->when($filters['vehicle_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['driver_id'] ?? null, fn (Builder $q) => $q->whereIn('id', $trips->pluck('vehicle_id')->merge($distances->pluck('vehicle_id'))->unique()))
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'vehicle_type', 'make', 'model', 'status'])
            ->map(function (Vehicle $vehicle) use ($trips, $distances, $start, $end, $days) {
                $own = $trips->where('vehicle_id', $vehicle->id);
                $legs = $distances->where('vehicle_id', $vehicle->id);
                $active = $this->activeDays($own, $start, $end);
                $hours = $this->activeHours($own, $start, $end);

                return [
                    'id' => $vehicle->id,
                    'plate_number' => $vehicle->plate_number,
                    'vehicle_type' => $vehicle->vehicle_type?->value,
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'status' => $vehicle->status?->value,
                    'distance_km' => $legs->isEmpty() ? null : round($legs->sum('km'), 1),
                    'trips' => $own->count(),
                    'completed_trips' => $own->where('status', TripStatus::Completed)->count(),
                    'active_days' => $active,
                    'utilization_rate' => round($active / $days * 100, 1),
                    'active_hours' => round($hours, 1),
                    'idle_hours' => round($days * 24 - $hours, 1),
                ];
            });
    }

    private function utilizationTotals(Collection $rows): array
    {
        $distances = $rows->whereNotNull('distance_km');

        return [
            'utilization_rate' => $rows->isEmpty() ? null : round($rows->avg('utilization_rate'), 1),
            'active_vehicles' => $rows->where('trips', '>', 0)->count(),
            'idle_vehicles' => $rows->where('trips', 0)->count(),
            'active_hours' => round($rows->sum('active_hours'), 1),
            'distance_km' => $distances->isEmpty() ? null : round($distances->sum('distance_km'), 1),
        ];
    }

    /** Hours in [$start, $end] covered by the trips' time on the road, overlaps counted once. */
    private function activeHours(Collection $trips, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $spans = $trips->map(fn ($trip) => [
            max($trip->departure_at->toImmutable(), $start)->getTimestamp(),
            min(($trip->actual_arrival_at ?? $trip->estimated_arrival_at ?? $trip->departure_at)->toImmutable(), $end)->getTimestamp(),
        ])->filter(fn ($span) => $span[1] > $span[0])->sortBy(0)->values();

        $seconds = 0;
        $cursor = null;
        foreach ($spans as [$from, $to]) {
            $from = max($from, $cursor ?? $from);
            if ($to > $from) {
                $seconds += $to - $from;
            }
            $cursor = max($cursor ?? $to, $to);
        }

        return $seconds / 3600;
    }

    private function orders(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return $this->filtered(Order::query(), $filters)->whereBetween('scheduled_delivery_at', [$start, $end])
            ->when($filters['order_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->when($filters['customer'] ?? null, fn (Builder $q, $customer) => $q->where('customer_name', $customer));
    }

    private function deliveryTotals(Collection $orders): array
    {
        $delivered = $orders->where('status', OrderStatus::Delivered);
        $timed = $delivered->whereNotNull('delivered_at');

        return [
            'total_orders' => $orders->count(),
            'delivered' => $delivered->count(),
            'on_time_rate' => $timed->isEmpty() ? null : round($timed->filter(fn (Order $o) => $o->delivered_at->lte($o->scheduled_delivery_at))->count() / $timed->count() * 100, 1),
            'delayed' => $orders->filter(fn (Order $o) => $o->isDelayed())->count(),
            'cancelled' => $orders->where('status', OrderStatus::Cancelled)->count(),
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
     * @return Collection<int, array{vehicle_id: int, driver_id: int|null, day: string, km: float, liters: float}>
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
            ->get(['id', 'vehicle_id', 'driver_id', 'fueled_at', 'odometer_km', 'liters']);

        return $readings->groupBy('vehicle_id')->flatMap(function (Collection $rows) use ($closing) {
            $legs = [];
            $previous = null;
            foreach ($rows as $row) {
                if ($previous !== null && $closing->has($row->id) && $row->odometer_km > $previous) {
                    $legs[] = ['vehicle_id' => $row->vehicle_id, 'driver_id' => $row->driver_id, 'day' => $row->fueled_at->toDateString(), 'km' => (float) ($row->odometer_km - $previous), 'liters' => (float) $row->liters];
                }
                $previous = max($previous ?? 0, $row->odometer_km);
            }

            return $legs;
        })->values();
    }

    /** Distinct days in [$start, $end] covered by any of the trips' time on the road. */
    private function activeDays(Collection $trips, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return count($this->activeDayKeys($trips, $start, $end));
    }

    /** @return list<string> */
    private function activeDayKeys(Collection $trips, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];
        foreach ($trips as $trip) {
            $from = max($trip->departure_at->toImmutable(), $start);
            $to = min(($trip->actual_arrival_at ?? $trip->estimated_arrival_at ?? $trip->departure_at)->toImmutable(), $end);
            for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
            }
        }

        return array_keys($days);
    }

    private function trips(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        return $this->filtered(Trip::query(), $filters)->whereBetween('departure_at', [$start, $end])
            ->when($filters['trip_status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status));
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
