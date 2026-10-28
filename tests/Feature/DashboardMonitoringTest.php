<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_counts_from_the_database(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Order::factory()->count(3)->create();
        Order::factory()->create(['status' => OrderStatus::Delivered]);
        Trip::factory()->create(['status' => TripStatus::InProgress]);
        Trip::factory()->create(['status' => TripStatus::Completed, 'actual_arrival_at' => now()]);
        Vehicle::factory()->create(['status' => VehicleStatus::Maintenance]);

        $response = $this->getJson($this->api('dashboard?days=7'))->assertOk();

        $response->assertJsonPath('data.stats.total_orders', 4)
            ->assertJsonPath('data.stats.pending_deliveries', 3)
            ->assertJsonPath('data.stats.active_trips', 1)
            ->assertJsonPath('data.stats.completed_trips', 1)
            ->assertJsonPath('data.stats.available_vehicles', 2)
            ->assertJsonPath('data.stats.total_drivers', 2)
            ->assertJsonCount(7, 'data.activity')
            ->assertJsonCount(4, 'data.recent_orders')
            ->assertJsonCount(1, 'data.active_trips');

        $this->assertSame(1, collect($response->json('data.activity'))->sum('trips_completed'));
        $this->assertSame(4, collect($response->json('data.order_status'))->sum('count'));
    }

    public function test_dashboard_is_empty_but_valid_with_no_data(): void
    {
        $this->actingAsRole();

        $this->getJson($this->api('dashboard'))->assertOk()
            ->assertJsonPath('data.stats.total_orders', 0)
            ->assertJsonCount(14, 'data.activity')
            ->assertJsonCount(0, 'data.recent_orders');
    }

    public function test_monitoring_overview_lists_assignments_and_expiring_licenses(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Trip::factory()->create(['status' => TripStatus::Dispatched]);
        Driver::factory()->create(['license_expiry' => now()->addDays(5)->toDateString()]);

        $this->getJson($this->api('fleet/monitoring'))->assertOk()
            ->assertJsonCount(1, 'data.active_assignments')
            ->assertJsonCount(1, 'data.expiring_licenses')
            ->assertJsonPath('data.utilization.vehicles_total', 1);
    }

    public function test_routing_reports_unconfigured_provider_instead_of_inventing_routes(): void
    {
        $this->actingAsRole();
        $trip = Trip::factory()->create();

        $this->getJson($this->api('routing/status'))->assertOk()->assertJsonPath('data.configured', false);
        $this->postJson($this->api("trips/{$trip->id}/route/optimize"))->assertStatus(503);
    }

    public function test_gps_shows_no_positions_until_a_device_reports(): void
    {
        $this->actingAsRole(UserRole::Admin);
        $vehicle = Vehicle::factory()->create();

        $this->getJson($this->api('gps/vehicles'))->assertOk()
            ->assertJsonPath('meta.has_gps_data', false)
            ->assertJsonPath('data.0.latest_location', null);

        $this->postJson($this->api('gps/locations'), [
            'vehicle_id' => $vehicle->id, 'latitude' => 14.5995, 'longitude' => 120.9842,
            'speed_kph' => 42, 'recorded_at' => now()->subMinute()->toIso8601String(), 'source' => 'test-device',
        ])->assertCreated();

        $this->getJson($this->api('gps/vehicles'))
            ->assertJsonPath('meta.has_gps_data', true)
            ->assertJsonPath('data.0.latest_location.latitude', 14.5995);
        $this->getJson($this->api("gps/vehicles/{$vehicle->id}/history"))->assertJsonCount(1, 'data');

        $this->postJson($this->api('gps/locations'), ['vehicle_id' => $vehicle->id, 'latitude' => 200])
            ->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude', 'recorded_at']);
    }

    public function test_search_and_meta_endpoints(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Vehicle::factory()->create(['plate_number' => 'ABC-1234']);

        $this->getJson($this->api('search?q=abc-12'))->assertOk()->assertJsonPath('data.0.type', 'vehicle');
        $this->getJson($this->api('search?q=a'))->assertJsonCount(0, 'data');
        $this->getJson($this->api('meta/options'))->assertOk()->assertJsonFragment(['value' => 'in_transit', 'label' => 'In Transit']);
    }
}
