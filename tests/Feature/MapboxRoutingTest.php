<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MapboxRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.routing.provider' => 'mapbox', 'services.mapbox.token' => 'sk.test']);

        $places = ['Depot' => [121.0, 14.6], 'Lipa' => [121.16, 13.94], 'Calamba' => [121.16, 14.21], 'Batangas' => [121.06, 13.76], 'Taguig City' => [121.05, 14.52]];
        Http::fake([
            'api.mapbox.com/search/geocode/*' => function ($request) use ($places) {
                // A street-level query that Mapbox matches to the wrong city.
                if ($request['q'] === 'Bldg 3 FTI Complex, Taguig City') {
                    return Http::response(['features' => [['geometry' => ['coordinates' => [120.98, 14.6]], 'properties' => ['full_address' => 'Barangay 3, Manila, Philippines']]]]);
                }
                $coordinates = $places[$request['q']] ?? null;

                return Http::response(['features' => $coordinates ? [['geometry' => ['coordinates' => $coordinates], 'properties' => ['full_address' => $request['q'].', Philippines']]] : []]);
            },
            // Calamba (input 2) is visited before Lipa (input 1); Batangas stays last.
            'api.mapbox.com/optimized-trips/*' => Http::response([
                'code' => 'Ok',
                'waypoints' => [['waypoint_index' => 0], ['waypoint_index' => 2], ['waypoint_index' => 1], ['waypoint_index' => 3]],
                'trips' => [['distance' => 93600, 'duration' => 7200, 'geometry' => 'abc', 'legs' => [['distance' => 40000, 'duration' => 3000], ['distance' => 30000, 'duration' => 2200], ['distance' => 23600, 'duration' => 2000]]]],
            ]),
            'api.mapbox.com/directions/*' => Http::response(['routes' => [['distance' => 120000, 'duration' => 9000, 'geometry' => 'abc', 'legs' => [['distance' => 120000, 'duration' => 9000]]]]]),
            'api.mapbox.com/styles/*' => Http::response('PNG', 200, ['Content-Type' => 'image/png']),
        ]);
    }

    private function stops(): array
    {
        return [['label' => 'Depot'], ['label' => 'Lipa'], ['label' => 'Calamba'], ['label' => 'Batangas']];
    }

    public function test_status_reports_mapbox_when_configured(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $this->getJson($this->api('routing/status'))->assertJsonPath('data.configured', true)->assertJsonPath('data.provider', 'mapbox');
    }

    public function test_preview_routes_and_optimizes_unsaved_stops(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->postJson($this->api('routing/preview'), ['stops' => $this->stops()])
            ->assertOk()
            ->assertJsonPath('data.order', [0, 1, 2, 3])
            ->assertJsonPath('data.distance_km', 120)
            ->assertJsonPath('data.duration_minutes', 150)
            ->assertJsonPath('data.points.1.matched', 'Lipa, Philippines')
            ->assertJsonPath('data.image', 'data:image/png;base64,'.base64_encode('PNG'));

        $this->postJson($this->api('routing/preview'), ['stops' => $this->stops(), 'optimize' => true])
            ->assertOk()
            ->assertJsonPath('data.order', [0, 2, 1, 3])
            ->assertJsonPath('data.distance_km', 93.6)
            ->assertJsonCount(3, 'data.legs');

        // Every Mapbox call is made server-side with the configured token.
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'access_token=sk.test'));
    }

    public function test_unknown_places_are_reported_not_guessed(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->postJson($this->api('routing/preview'), ['stops' => [['label' => 'Depot'], ['label' => 'Nowhere'], ['label' => 'Lipa']], 'optimize' => true])
            ->assertOk()
            ->assertJsonPath('data.unresolved', ['Nowhere'])
            ->assertJsonPath('data.order', [0, 1, 2])
            ->assertJsonPath('data.points.1.resolved', false);

        $this->postJson($this->api('routing/preview'), ['stops' => [['label' => 'Depot']]])->assertStatus(422);
    }

    public function test_saved_trip_is_optimized_into_stop_ids(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $trip = Trip::factory()->create(['origin' => 'Depot', 'destination' => 'Batangas']);
        $first = $trip->stops()->create(['sequence' => 1, 'type' => 'pickup', 'location_name' => 'Depot', 'status' => 'pending']);
        $lipa = $trip->stops()->create(['sequence' => 2, 'type' => 'stop', 'location_name' => 'Lipa', 'status' => 'pending']);
        $calamba = $trip->stops()->create(['sequence' => 3, 'type' => 'stop', 'location_name' => 'Calamba', 'status' => 'pending']);
        $last = $trip->stops()->create(['sequence' => 4, 'type' => 'dropoff', 'location_name' => 'Batangas', 'status' => 'pending']);

        $this->postJson($this->api("trips/{$trip->id}/route/optimize"))
            ->assertOk()
            ->assertJsonPath('data.stop_ids', [$first->id, $calamba->id, $lipa->id, $last->id])
            ->assertJsonPath('data.distance_km', 93.6);
    }

    public function test_a_match_outside_the_address_city_falls_back_to_the_city(): void
    {
        $this->actingAsRole(UserRole::Viewer);

        $this->postJson($this->api('routing/preview'), ['stops' => [['label' => 'Origin', 'query' => 'Bldg 3 FTI Complex, Taguig City'], ['label' => 'Lipa']]])
            ->assertOk()
            ->assertJsonPath('data.points.0.matched', 'Taguig City, Philippines')
            ->assertJsonPath('data.points.0.lat', 14.52);
    }
}
