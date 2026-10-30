<?php

namespace Tests\Feature;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\FuelRecord;
use App\Models\MaintenanceRecord;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Reports → Trip, Driver, Maintenance, Utilization and Delivery Reports, and saved Custom Reports. */
class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    private const OCT = 'from=2026-10-01&to=2026-10-31';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 12:00:00');
    }

    private function trip(Vehicle $vehicle, Driver $driver, string $departure, TripStatus $status, array $overrides = []): Trip
    {
        $at = Carbon::parse($departure);

        return Trip::factory()->create([
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'status' => $status,
            'departure_at' => $at, 'estimated_arrival_at' => $at->copy()->addHours(4), ...$overrides,
        ]);
    }

    public function test_trip_report_computes_stats_daily_series_and_filters(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $truck = Vehicle::factory()->create();
        $ana = Driver::factory()->create();
        $ben = Driver::factory()->create();

        // On time (3 h on the road, 120 km), late (6 h), cancelled, and one still scheduled.
        $this->trip($truck, $ana, '2026-10-03 08:00', TripStatus::Completed, ['actual_departure_at' => '2026-10-03 08:00', 'actual_arrival_at' => '2026-10-03 11:00', 'distance_km' => 120]);
        $this->trip($truck, $ben, '2026-10-03 13:00', TripStatus::Completed, ['actual_departure_at' => '2026-10-03 13:00', 'actual_arrival_at' => '2026-10-03 19:00']);
        $this->trip($truck, $ben, '2026-10-10 08:00', TripStatus::Cancelled);
        $this->trip($truck, $ana, '2026-10-20 08:00', TripStatus::Scheduled);
        $this->trip($truck, $ana, '2026-09-20 08:00', TripStatus::Completed, ['actual_arrival_at' => '2026-09-20 11:00']);

        $this->getJson($this->api('reports/trips?'.self::OCT))->assertOk()
            ->assertJsonPath('data.stats.total_trips', ['value' => 4, 'previous' => 1])
            ->assertJsonPath('data.stats.completed', ['value' => 2, 'previous' => 1])
            ->assertJsonPath('data.stats.cancelled.value', 1)
            ->assertJsonPath('data.stats.on_time_rate', ['value' => 50, 'previous' => 100])
            ->assertJsonPath('data.stats.avg_duration_hours', ['value' => 4.5, 'previous' => null])
            ->assertJsonPath('data.stats.distance_km', ['value' => 120, 'previous' => null])
            ->assertJsonCount(31, 'data.daily')
            ->assertJsonPath('data.daily.2', ['date' => '2026-10-03', 'trips' => 2, 'completed' => 2])
            ->assertJsonPath('data.trip_status.0.count', 2);

        $this->getJson($this->api('reports/trips?'.self::OCT."&driver_id={$ben->id}&trip_status=completed"))->assertOk()
            ->assertJsonPath('data.stats.total_trips.value', 1)
            ->assertJsonPath('data.stats.on_time_rate.value', 0);
        $this->getJson($this->api('reports/trips?'.self::OCT.'&trip_status=lost'))->assertStatus(422)->assertJsonValidationErrors(['trip_status']);
    }

    public function test_driver_report_lists_each_driver_with_trip_outcomes(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $truck = Vehicle::factory()->create();
        $van = Vehicle::factory()->create();
        $ana = Driver::factory()->create(['full_name' => 'Ana Cruz']);
        $ben = Driver::factory()->create(['full_name' => 'Ben Reyes']);
        Driver::factory()->create(['full_name' => 'Cara Lim']);

        $this->trip($truck, $ana, '2026-10-03 08:00', TripStatus::Completed, ['actual_departure_at' => '2026-10-03 08:00', 'actual_arrival_at' => '2026-10-03 11:30']);
        $this->trip($truck, $ana, '2026-10-05 08:00', TripStatus::Dispatched);
        $this->trip($van, $ana, '2026-10-06 08:00', TripStatus::Cancelled);
        $this->trip($van, $ben, '2026-10-07 08:00', TripStatus::Completed, ['actual_departure_at' => '2026-10-07 08:00', 'actual_arrival_at' => '2026-10-07 14:00']);
        FuelRecord::factory()->create(['vehicle_id' => $truck->id, 'driver_id' => $ana->id, 'fueled_at' => '2026-09-30 08:00', 'odometer_km' => 1_000]);
        FuelRecord::factory()->create(['vehicle_id' => $truck->id, 'driver_id' => $ana->id, 'fueled_at' => '2026-10-04 08:00', 'odometer_km' => 1_300]);

        $data = $this->getJson($this->api('reports/drivers?'.self::OCT))->assertOk()
            ->assertJsonCount(3, 'data.drivers')
            ->assertJsonPath('data.stats.active_drivers', ['value' => 2, 'previous' => 0])
            ->assertJsonPath('data.stats.total_trips.value', 4)
            // 2 completed of 3 not cancelled; 1 of 2 arrivals on time; 3.5 + 6 hours
            ->assertJsonPath('data.stats.completion_rate.value', 66.7)
            ->assertJsonPath('data.stats.on_time_rate.value', 50)
            ->assertJsonPath('data.stats.driving_hours.value', 9.5)
            ->json('data.drivers');

        $this->assertSame(['trips' => 3, 'completed' => 1, 'pending' => 1, 'cancelled' => 1, 'completion_rate' => 50, 'on_time_rate' => 100, 'driving_hours' => 3.5, 'distance_km' => 300],
            collect($data[0])->only(['trips', 'completed', 'pending', 'cancelled', 'completion_rate', 'on_time_rate', 'driving_hours', 'distance_km'])->all());
        $this->assertSame('Ana Cruz', $data[0]['full_name']);
        $this->assertNull($data[2]['completion_rate']);

        // A vehicle filter lists only the drivers of that vehicle.
        $this->getJson($this->api('reports/drivers?'.self::OCT."&vehicle_id={$van->id}"))->assertOk()
            ->assertJsonCount(2, 'data.drivers')
            ->assertJsonPath('data.drivers.0.trips', 1);
    }

    public function test_maintenance_report_breaks_down_work_and_lists_upcoming(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $truck = Vehicle::factory()->create(['plate_number' => 'TRK-001']);
        $van = Vehicle::factory()->create(['plate_number' => 'VAN-003']);

        MaintenanceRecord::factory()->completed('2026-10-04')->create(['vehicle_id' => $truck->id, 'actual_cost' => 4_500]);
        MaintenanceRecord::factory()->completed('2026-10-12')->create(['vehicle_id' => $van->id, 'actual_cost' => 1_500, 'service_type' => MaintenanceType::TireService]);
        MaintenanceRecord::factory()->create(['vehicle_id' => $van->id, 'scheduled_date' => '2026-10-20']); // overdue
        MaintenanceRecord::factory()->create(['vehicle_id' => $truck->id, 'scheduled_date' => '2026-10-28', 'status' => MaintenanceStatus::Cancelled]);
        MaintenanceRecord::factory()->create(['vehicle_id' => $truck->id, 'scheduled_date' => '2026-11-09']); // due soon, outside October

        $this->getJson($this->api('reports/maintenance?'.self::OCT))->assertOk()
            ->assertJsonPath('data.stats.total', ['value' => 4, 'previous' => 0])
            ->assertJsonPath('data.stats.completed.value', 2)
            ->assertJsonPath('data.stats.overdue.value', 1)
            ->assertJsonPath('data.stats.total_cost', ['value' => 6000, 'previous' => null])
            ->assertJsonPath('data.by_type.0', ['key' => 'oil_change', 'label' => 'Oil Change', 'count' => 3, 'cost' => 4500])
            ->assertJsonPath('data.by_vehicle.0.label', 'TRK-001')
            ->assertJsonCount(3, 'data.by_status')
            ->assertJsonCount(2, 'data.upcoming')
            ->assertJsonPath('data.upcoming.0.display_status', 'overdue')
            ->assertJsonPath('data.upcoming.1.display_status', 'due_soon');

        $this->getJson($this->api('reports/maintenance?'.self::OCT."&status=overdue&vehicle_id={$van->id}"))->assertOk()
            ->assertJsonPath('data.stats.total.value', 1)
            ->assertJsonPath('data.stats.total_cost.value', null);
    }

    public function test_utilization_report_computes_days_hours_and_idle_vehicles(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $truck = Vehicle::factory()->create(['plate_number' => 'AAA-111']);
        Vehicle::factory()->create(['plate_number' => 'ZZZ-999']);
        $driver = Driver::factory()->create();

        // Overnight 10 h trip and an overlapping 4 h trip (counted once), plus a cancelled one.
        $this->trip($truck, $driver, '2026-10-02 20:00', TripStatus::Completed, ['actual_arrival_at' => '2026-10-03 06:00']);
        $this->trip($truck, $driver, '2026-10-03 04:00', TripStatus::Completed, ['actual_arrival_at' => '2026-10-03 08:00']);
        $this->trip($truck, $driver, '2026-10-15 08:00', TripStatus::Cancelled);

        $this->getJson($this->api('reports/utilization?'.self::OCT))->assertOk()
            ->assertJsonPath('data.vehicles.0.trips', 2)
            ->assertJsonPath('data.vehicles.0.active_days', 2)
            ->assertJsonPath('data.vehicles.0.utilization_rate', 6.5)
            ->assertJsonPath('data.vehicles.0.active_hours', 12)
            ->assertJsonPath('data.vehicles.0.idle_hours', 732)
            ->assertJsonPath('data.vehicles.1.utilization_rate', 0)
            ->assertJsonPath('data.stats.active_vehicles.value', 1)
            ->assertJsonPath('data.stats.idle_vehicles.value', 1)
            ->assertJsonPath('data.stats.utilization_rate.value', 3.3)
            ->assertJsonPath('data.stats.distance_km.value', null)
            ->assertJsonPath('data.daily.1', ['date' => '2026-10-02', 'active_vehicles' => 1])
            ->assertJsonPath('data.daily.3.active_vehicles', 0);

        $this->getJson($this->api('reports/utilization?'.self::OCT."&driver_id={$driver->id}"))->assertJsonCount(1, 'data.vehicles');
    }

    public function test_delivery_report_and_order_delivery_filter(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $order = fn (OrderStatus $status, string $due, ?string $delivered = null, array $more = []) => Order::factory()->create([
            'status' => $status, 'scheduled_pickup_at' => Carbon::parse($due)->subHours(6), 'scheduled_delivery_at' => $due, 'delivered_at' => $delivered, ...$more,
        ]);
        $order(OrderStatus::Delivered, '2026-10-05 17:00', '2026-10-05 15:00', ['customer_name' => 'Acme']);
        $order(OrderStatus::Delivered, '2026-10-06 17:00', '2026-10-06 19:00', ['customer_name' => 'Acme']);
        $order(OrderStatus::InTransit, '2026-10-30 17:00', null, ['customer_name' => 'Bolt']); // delayed
        $order(OrderStatus::Cancelled, '2026-10-12 17:00');
        $order(OrderStatus::Pending, '2026-11-20 17:00');

        $this->getJson($this->api('reports/deliveries?'.self::OCT))->assertOk()
            ->assertJsonPath('data.stats.total_orders', ['value' => 4, 'previous' => 0])
            ->assertJsonPath('data.stats.delivered.value', 2)
            ->assertJsonPath('data.stats.on_time_rate', ['value' => 50, 'previous' => null])
            ->assertJsonPath('data.stats.delayed.value', 1)
            ->assertJsonPath('data.stats.cancelled.value', 1)
            ->assertJsonPath('data.daily.4', ['date' => '2026-10-05', 'scheduled' => 1, 'delivered' => 1])
            ->assertJsonPath('data.customers.0', ['key' => 'Acme', 'label' => 'Acme', 'count' => 2, 'delivered' => 2]);

        $this->getJson($this->api('reports/deliveries?'.self::OCT.'&customer=Bolt&order_status=in_transit'))->assertJsonPath('data.stats.total_orders.value', 1);
        $this->getJson($this->api('orders?delivery_from=2026-10-01&delivery_to=2026-10-31&sort=-delivered_at'))->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_saves_custom_report_configurations(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $config = ['source' => 'trips', 'columns' => ['trip_number', 'driver', 'status'], 'sort' => '-departure_at', 'filters' => ['status' => 'completed']];

        $this->postJson($this->api('reports'), ['name' => 'Completed trips', 'type' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'config' => $config])
            ->assertCreated()->assertJsonPath('data.config.source', 'trips')->assertJsonPath('data.config.sort', '-departure_at')
            ->assertJsonPath('data.config.filters', ['status' => 'completed']);
        $this->getJson($this->api('reports?type=custom'))->assertJsonPath('data.0.config.columns', ['trip_number', 'driver', 'status']);

        $this->postJson($this->api('reports'), ['name' => 'No config', 'type' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors(['config']);
        $this->postJson($this->api('reports'), ['name' => 'Bad', 'type' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'config' => ['source' => 'users', 'columns' => []]])
            ->assertStatus(422)->assertJsonValidationErrors(['config.source', 'config.columns']);
        $this->postJson($this->api('reports'), ['name' => 'Fleet', 'type' => 'fleet', 'from' => '2026-10-01', 'to' => '2026-10-31', 'config' => $config])
            ->assertStatus(422)->assertJsonValidationErrors(['config']);
    }

    public function test_report_pages_require_sign_in(): void
    {
        foreach (['trips', 'drivers', 'maintenance', 'utilization', 'deliveries'] as $page) {
            $this->getJson($this->api("reports/{$page}?".self::OCT))->assertUnauthorized();
        }
    }
}
