<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoutePlanningTest extends TestCase
{
    use RefreshDatabase;

    private function tripWithStops(int $intermediate): Trip
    {
        $trip = Trip::factory()->create();
        $count = $intermediate + 2;
        for ($i = 1; $i <= $count; $i++) {
            $trip->stops()->create(['sequence' => $i, 'type' => $i === 1 ? 'pickup' : ($i === $count ? 'dropoff' : 'stop'), 'location_name' => "Stop {$i}", 'status' => 'pending']);
        }

        return $trip;
    }

    public function test_optimized_reorder_marks_the_route_and_manual_changes_clear_it(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $trip = $this->tripWithStops(2);
        [$a, $b] = $trip->stops()->where('type', 'stop')->pluck('id')->all();

        $this->putJson($this->api("trips/{$trip->id}/stops/reorder"), ['stop_ids' => [$b, $a], 'optimized' => true, 'distance_km' => 93.6])->assertOk();
        $trip->refresh();
        $this->assertNotNull($trip->route_optimized_at);
        $this->assertEquals(93.6, (float) $trip->distance_km);

        $this->getJson($this->api('trips?route=optimized'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $trip->id);
        $this->getJson($this->api('trips?route=unoptimized'))->assertJsonCount(0, 'data');

        // A manual reorder, or adding a stop, means the order is no longer the optimized one.
        $this->putJson($this->api("trips/{$trip->id}/stops/reorder"), ['stop_ids' => [$a, $b]])->assertOk();
        $this->assertNull($trip->fresh()->route_optimized_at);
        $this->getJson($this->api('trips?route=unoptimized'))->assertJsonCount(1, 'data');

        $this->putJson($this->api("trips/{$trip->id}/stops/reorder"), ['stop_ids' => [$b, $a], 'optimized' => true])->assertOk();
        $this->postJson($this->api("trips/{$trip->id}/stops"), ['location_name' => 'Extra'])->assertCreated();
        $this->assertNull($trip->fresh()->route_optimized_at);
    }

    public function test_only_trips_with_stops_to_reorder_need_planning(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $this->tripWithStops(0);
        $this->tripWithStops(1);
        $needs = $this->tripWithStops(2);

        $this->getJson($this->api('trips?route=unoptimized'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $needs->id);
    }

    public function test_vehicle_track_returns_positions_and_a_map_when_configured(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $vehicle = Vehicle::factory()->create();
        foreach ([[14.60, 121.00], [14.55, 121.02], [14.50, 121.05]] as $i => [$lat, $lng]) {
            VehicleLocation::create(['vehicle_id' => $vehicle->id, 'latitude' => $lat, 'longitude' => $lng, 'speed_kph' => 40, 'recorded_at' => now()->subMinutes(30 - $i * 10)]);
        }

        $this->getJson($this->api("gps/vehicles/{$vehicle->id}/track"))->assertOk()
            ->assertJsonCount(3, 'data.locations')
            ->assertJsonPath('data.locations.0.latitude', 14.5)
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('meta.map_provider_configured', false);

        config(['services.routing.provider' => 'mapbox', 'services.mapbox.token' => 'sk.test']);
        Http::fake(['api.mapbox.com/styles/*' => Http::response('PNG', 200)]);

        $this->getJson($this->api("gps/vehicles/{$vehicle->id}/track"))->assertOk()
            ->assertJsonPath('data.image', 'data:image/png;base64,'.base64_encode('PNG'));
        // Encoded polyline of the sample track (precision 5), oldest point first.
        Http::assertSent(fn ($request) => str_contains(rawurldecode($request->url()), 'path-4+0284c7-0.8(_qbxA_yoaVnwH_|BnwHozD)'));
    }
}
