<?php

namespace Tests\Feature;

use App\Enums\MaintenanceStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\MaintenanceRecord;
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

    public function test_viewport_map_fits_reported_positions_without_drawing_pins(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $this->mapbox();
        $a = Vehicle::factory()->create();
        $b = Vehicle::factory()->create();
        $silent = Vehicle::factory()->create();
        VehicleLocation::create(['vehicle_id' => $a->id, 'latitude' => 14.0, 'longitude' => 121.0, 'recorded_at' => now()]);
        VehicleLocation::create(['vehicle_id' => $b->id, 'latitude' => 14.4, 'longitude' => 121.4, 'recorded_at' => now()]);

        $view = $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => [$a->id, $b->id, $silent->id], 'width' => 800, 'height' => 400])
            ->assertOk()->assertJsonPath('data.image', 'data:image/png;base64,'.base64_encode('PNG'))->json('data.viewport');

        $this->assertEqualsWithDelta(121.2, $view['lng'], 0.001);
        $this->assertEqualsWithDelta(14.2, $view['lat'], 0.01);
        $this->assertGreaterThan(8, $view['zoom']);
        $this->assertLessThan(12, $view['zoom']);
        $this->assertSame([800, 400], [$view['width'], $view['height']]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'streets-v12/static/121.200000,') && str_contains($r->url(), '/800x400@2x') && ! str_contains($r->url(), 'pin-'));

        // Padding on one side shifts the center so the points sit in the clear area.
        $padded = $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => [$a->id, $b->id], 'width' => 800, 'height' => 400, 'padding' => [60, 60, 60, 300]])
            ->assertOk()->json('data.viewport');
        $this->assertLessThan($view['lng'], $padded['lng']);
        $this->assertSame($view['zoom'], $padded['zoom']); // height still decides the zoom
        $narrow = $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => [$a->id, $b->id], 'width' => 600, 'height' => 400, 'padding' => [60, 60, 60, 400]])
            ->assertOk()->json('data.viewport');
        $this->assertLessThan($view['zoom'], $narrow['zoom']);
    }

    public function test_viewport_map_accepts_an_explicit_view_and_falls_back_to_the_service_area(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $this->mapbox();

        $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => [], 'lng' => 121.05, 'lat' => 14.55, 'zoom' => 14, 'style' => 'satellite'])
            ->assertOk()->assertJsonPath('data.viewport.zoom', 14)->assertJsonPath('data.viewport.width', 900);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'satellite-streets-v12/static/121.050000,14.550000,14/900x560@2x'));

        // No positions at all: the configured service area.
        $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => []])->assertOk()->assertJsonPath('data.viewport.zoom', 8.5);

        $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => [], 'zoom' => 30])
            ->assertStatus(422)->assertJsonValidationErrors(['zoom', 'lng', 'lat']);
    }

    public function test_viewport_map_has_no_image_without_a_map_provider(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->postJson($this->api('gps/viewport'), ['vehicle_ids' => []])->assertOk()->assertJsonPath('data.image', null);
    }

    public function test_fleet_trend_rebuilds_status_counts_for_now_and_last_month(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $this->actingAsRole(UserRole::Viewer);
        $old = Vehicle::factory()->count(3)->create(['created_at' => now()->subMonths(2)]);
        Vehicle::factory()->create(['created_at' => now()->subWeek()]);

        // On route last month (arrived since), on route now.
        Trip::factory()->create(['vehicle_id' => $old[0]->id, 'status' => TripStatus::Completed, 'dispatched_at' => now()->subMonth()->subHour(), 'actual_arrival_at' => now()->subMonth()->addHour()]);
        Trip::factory()->create(['vehicle_id' => $old[1]->id, 'status' => TripStatus::InProgress, 'dispatched_at' => now()->subHour()]);
        // In the workshop now.
        MaintenanceRecord::factory()->create(['vehicle_id' => $old[2]->id, 'status' => MaintenanceStatus::InProgress, 'started_at' => now()->subDay(), 'completed_at' => null]);

        $data = $this->getJson($this->api('gps/fleet-trend'))->assertOk()->json('data');

        $this->assertSame(['total' => 4, 'on_route' => 1, 'maintenance' => 1, 'idle' => null, 'offline' => null], $data['now']);
        $this->assertSame(['total' => 3, 'on_route' => 1, 'maintenance' => 0, 'idle' => null, 'offline' => null], $data['last_month']);

        // Once positions exist, idle and offline are counted for moments after the first report only.
        VehicleLocation::create(['vehicle_id' => $old[0]->id, 'latitude' => 14.2, 'longitude' => 121.1, 'recorded_at' => now()->subMinutes(5)]);
        $data = $this->getJson($this->api('gps/fleet-trend'))->assertOk()->json('data');
        $this->assertSame(1, $data['now']['idle']);
        $this->assertSame(1, $data['now']['offline']);
        $this->assertNull($data['last_month']['idle']);
    }
}
