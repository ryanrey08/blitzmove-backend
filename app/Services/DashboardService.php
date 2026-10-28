<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function summary(int $days = 14): array
    {
        return [
            'stats' => [
                'total_orders' => Order::count(),
                'active_trips' => Trip::whereIn('status', TripStatus::active())->count(),
                'available_vehicles' => Vehicle::where('status', VehicleStatus::Available)->count(),
                'total_vehicles' => Vehicle::count(),
                'total_drivers' => Driver::count(),
                'pending_deliveries' => Order::whereIn('status', OrderStatus::open())->count(),
                'completed_trips' => Trip::where('status', TripStatus::Completed)->count(),
            ],
            'activity' => $this->activity($days),
            'order_status' => $this->distribution(Order::query(), OrderStatus::class),
            'vehicle_status' => $this->distribution(Vehicle::query(), VehicleStatus::class),
            'driver_status' => $this->distribution(Driver::query(), DriverStatus::class),
        ];
    }

    /**
     * Orders created and trips completed per day.
     *
     * @return list<array{date: string, orders: int, trips_completed: int}>
     */
    public function activity(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $orders = Order::where('created_at', '>=', $from)->pluck('created_at')
            ->countBy(fn (Carbon $date) => $date->toDateString());
        $trips = Trip::where('status', TripStatus::Completed)->where('actual_arrival_at', '>=', $from)
            ->pluck('actual_arrival_at')->countBy(fn (Carbon $date) => $date->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $orders, $trips) {
            $date = $from->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'orders' => $orders[$date] ?? 0, 'trips_completed' => $trips[$date] ?? 0];
        })->all();
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<array{status: string, label: string, count: int}>
     */
    public function distribution(Builder $query, string $enum): array
    {
        $counts = $query->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return array_map(fn ($case) => [
            'status' => $case->value,
            'label' => $case->label(),
            'count' => (int) ($counts[$case->value] ?? 0),
        ], $enum::cases());
    }
}
