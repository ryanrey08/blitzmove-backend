<?php

namespace App\Services;

use App\Enums\MaintenanceStatus;
use App\Enums\TripStatus;
use App\Models\FuelRecord;
use App\Models\MaintenanceRecord;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Figures for the Monitoring page. Every number comes from trips, fuel records or reported GPS
 * positions; a figure with no source data is null rather than estimated.
 */
class MonitoringService
{
    /** At or below this speed a GPS sample counts as stopped and is left out of the average. */
    public const MOVING_KPH = 3;

    /** A vehicle whose last position is older than this counts as offline. */
    public const STALE_MINUTES = 15;

    public function overview(): array
    {
        $now = now();
        $active = Trip::whereIn('status', TripStatus::active());
        $lastReported = VehicleLocation::max('recorded_at');
        $today = $this->day($now->copy()->startOfDay(), $now);
        // Trips still out after an arrival due before today are delayed today too.
        $today['delayed'] += (clone $active)->where('estimated_arrival_at', '<', $now->copy()->startOfDay())->count();

        return [
            'active_trips' => [
                'total' => (clone $active)->count(),
                'on_time' => (clone $active)->where('estimated_arrival_at', '>=', $now)->count(),
                'delayed' => (clone $active)->where('estimated_arrival_at', '<', $now)->count(),
                'cancelled_today' => $this->cancelled($now),
            ],
            // Today so far, and the same weekday last week for the trend arrows.
            'today' => $today,
            'last_week' => $this->day($now->copy()->subWeek()->startOfDay(), $now->copy()->subWeek()->endOfDay()),
            'gps' => [
                'has_data' => $lastReported !== null,
                // max() returns the raw UTC column value; send ISO 8601 so clients don't read it as local time.
                'last_reported_at' => $lastReported ? Carbon::parse($lastReported)->toIso8601String() : null,
            ],
        ];
    }

    /**
     * How the fleet stood at $at, rebuilt from records so two moments can be compared:
     * on route = dispatched and not yet arrived; in maintenance = maintenance work started and not
     * completed (and not on route); idle = reported a position in the STALE_MINUTES before $at;
     * offline = everyone else. Idle and offline are null when no position had been reported by $at.
     *
     * @return array{total: int, on_route: int, maintenance: int, idle: int|null, offline: int|null}
     */
    public function fleetSnapshot(CarbonInterface $at): array
    {
        $vehicleIds = Vehicle::withTrashed()->where('created_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>', $at))->pluck('id');

        // Trips that were reverted or cancelled count until their last update.
        $onRoute = Trip::whereIn('vehicle_id', $vehicleIds)->where('dispatched_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('actual_arrival_at')->orWhere('actual_arrival_at', '>', $at))
            ->where(fn ($q) => $q->whereNotIn('status', [TripStatus::Scheduled, TripStatus::Cancelled])->orWhere('updated_at', '>', $at))
            ->distinct()->pluck('vehicle_id');

        $maintenance = MaintenanceRecord::whereIn('vehicle_id', $vehicleIds->diff($onRoute))->where('started_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>', $at))
            ->where(fn ($q) => $q->where('status', '!=', MaintenanceStatus::Cancelled)->orWhere('updated_at', '>', $at))
            ->distinct()->pluck('vehicle_id');

        $rest = $vehicleIds->diff($onRoute)->diff($maintenance);
        $idle = VehicleLocation::where('recorded_at', '<=', $at)->exists()
            ? VehicleLocation::whereIn('vehicle_id', $rest)->whereBetween('recorded_at', [$at->copy()->subMinutes(self::STALE_MINUTES), $at])->distinct()->count('vehicle_id')
            : null;

        return [
            'total' => $vehicleIds->count(),
            'on_route' => $onRoute->count(),
            'maintenance' => $maintenance->count(),
            'idle' => $idle,
            'offline' => $idle === null ? null : $rest->count() - $idle,
        ];
    }

    /** Totals for one day, counted from $start up to $end. */
    private function day(CarbonInterface $start, CarbonInterface $end): array
    {
        $completed = Trip::where('status', TripStatus::Completed)->whereBetween('actual_arrival_at', [$start, $end]);
        $speed = VehicleLocation::whereBetween('recorded_at', [$start, $end])->where('speed_kph', '>', self::MOVING_KPH)->avg('speed_kph');

        return [
            'completed' => (clone $completed)->count(),
            // Trips due that day that arrived late, or are still out past their estimated arrival.
            'delayed' => Trip::where('status', '!=', TripStatus::Cancelled)
                ->whereBetween('estimated_arrival_at', [$start, $end])
                ->where(fn ($q) => $q->whereColumn('actual_arrival_at', '>', 'estimated_arrival_at')
                    ->orWhere(fn ($q) => $q->whereNull('actual_arrival_at')->where('estimated_arrival_at', '<', now())))
                ->count(),
            'cancelled' => $this->cancelled($start),
            // Planned distance of trips completed that day; GPS odometry is not used.
            'distance_km' => round((float) (clone $completed)->sum('distance_km'), 2),
            'fuel_liters' => round((float) FuelRecord::whereBetween('fueled_at', [$start, $end])->sum('liters'), 2),
            'average_speed_kph' => $speed !== null ? round((float) $speed, 1) : null,
            'gps_samples' => VehicleLocation::whereBetween('recorded_at', [$start, $end])->count(),
        ];
    }

    /** Cancelled trips that were scheduled to depart on the day of $day. */
    private function cancelled(CarbonInterface $day): int
    {
        return Trip::where('status', TripStatus::Cancelled)
            ->whereBetween('departure_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->count();
    }
}
