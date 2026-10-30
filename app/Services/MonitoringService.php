<?php

namespace App\Services;

use App\Enums\TripStatus;
use App\Models\FuelRecord;
use App\Models\Trip;
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
