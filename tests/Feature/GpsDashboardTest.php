<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GpsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function mapbox(): void
    {
        config(['services.routing.provider' => 'mapbox', 'services.mapbox.token' => 'sk.test']);
        Http::fake([
            'api.mapbox.com/search/geocode/v6/reverse*' => Http::response(['features' => [['properties' => ['name' => 'SLEX', 'context' => ['place' => ['name' => 'Calamba'], 'region' => ['name' => 'Laguna']]]]]]),
            'api.mapbox.com/styles/*' => Http::response('PNG', 200),
        ]);
    }

    public function test_fleet_map_uses_stored_positions_and_ignores_vehicles_without_one(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $this->mapbox();
        $reporting = Vehicle::factory()->create();
        $silent = Vehicle::factory()->create();
        VehicleLocation::create(['vehicle_id' => $reporting->id, 'latitude' => 14.2, 'longitude' => 121.16, 'recorded_at' => now()->subHour()]);
        VehicleLocation::create(['vehicle_id' => $reporting->id, 'latitude' => 14.21, 'longitude' => 121.17, 'recorded_at' => now()]);

        $this->postJson($this->api('gps/map'), [
            'markers' => [['vehicle_id' => $reporting->id, 'label' => '1', 'color' => '#16a34a'], ['vehicle_id' => $silent->id, 'label' => '2', 'color' => 'e30613']],
            'style' => 'satellite',
        ])->assertOk()->assertJsonPath('data.plotted', 1)->assertJsonPath('data.image', 'data:image/png;base64,'.base64_encode('PNG'));

        // Latest position only, satellite style.
        Http::assertSent(fn ($r) => str_contains(rawurldecode($r->url()), 'satellite-streets-v12/static/pin-l-1+16a34a(121.170000,14.210000)'));

        $this->postJson($this->api('gps/map'), ['markers' => [['vehicle_id' => $reporting->id, 'label' => 'bad label', 'color' => 'red']]])
            ->assertStatus(422)->assertJsonValidationErrors(['markers.0.label', 'markers.0.color']);
    }

    public function test_vehicle_list_includes_place_names_when_a_map_provider_is_set_up(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $vehicle = Vehicle::factory()->create();
        VehicleLocation::create(['vehicle_id' => $vehicle->id, 'latitude' => 14.2, 'longitude' => 121.16, 'recorded_at' => now()]);

        $this->getJson($this->api('gps/vehicles'))->assertOk()->assertJsonPath('meta.places', []);

        $this->mapbox();
        $this->getJson($this->api('gps/vehicles'))->assertOk()->assertJsonPath("meta.places.{$vehicle->id}", 'SLEX, Calamba, Laguna');
    }

    public function test_events_list_todays_trip_and_stop_activity_newest_first(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $trip = Trip::factory()->create(['status' => TripStatus::InProgress, 'dispatched_at' => now()->subHours(3), 'actual_departure_at' => now()->subHours(2)]);
        $trip->stops()->create(['sequence' => 1, 'type' => 'pickup', 'location_name' => 'Depot', 'status' => 'completed', 'actual_arrival_at' => now()->subHour()]);
        Trip::factory()->create(['dispatched_at' => now()->subDays(2)]);

        $events = $this->getJson($this->api('gps/events'))->assertOk()->json('data');

        $this->assertSame(['arrived', 'departed', 'dispatched'], array_column($events, 'type'));
        $this->assertStringContainsString('Depot (Stop 1)', $events[0]['text']);
    }

    public function test_events_can_be_limited_to_one_vehicle(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $this->actingAsRole(UserRole::Viewer);
        $trip = Trip::factory()->create(['status' => TripStatus::InProgress, 'actual_departure_at' => now()->subHour()]);
        Trip::factory()->create(['status' => TripStatus::InProgress, 'actual_departure_at' => now()->subHour()]);

        $events = $this->getJson($this->api("gps/events?vehicle_id={$trip->vehicle_id}"))->assertOk()->json('data');

        $this->assertCount(1, $events);
        $this->assertSame($trip->vehicle_id, $events[0]['vehicle_id']);
        $this->assertSame($trip->origin, $events[0]['location']);
    }
}
