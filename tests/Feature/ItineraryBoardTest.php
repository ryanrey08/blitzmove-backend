<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItineraryBoardTest extends TestCase
{
    use RefreshDatabase;

    private function tripWithStops(array $attributes, array $names): Trip
    {
        $trip = Trip::factory()->create($attributes);
        foreach ($names as $i => $name) {
            $trip->stops()->create([
                'sequence' => $i + 1,
                'type' => $i === 0 ? 'pickup' : ($i === count($names) - 1 ? 'dropoff' : 'stop'),
                'location_name' => $name,
                'estimated_arrival_at' => $i === 1 ? null : $trip->departure_at->copy()->addHours($i),
                'status' => 'pending',
            ]);
        }

        return $trip;
    }

    public function test_board_lists_stops_across_trips_by_day_in_time_order(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $tomorrow = now()->addDay()->setTime(8, 0);
        $a = $this->tripWithStops(['departure_at' => $tomorrow, 'estimated_arrival_at' => $tomorrow->copy()->addHours(4)], ['Depot A', 'Mid A', 'End A']);
        $this->tripWithStops(['departure_at' => $tomorrow->copy()->addHour(), 'estimated_arrival_at' => $tomorrow->copy()->addHours(5)], ['Depot B', 'End B']);
        $this->tripWithStops(['departure_at' => now()->addDays(5), 'estimated_arrival_at' => now()->addDays(5)->addHours(3)], ['Later', 'Later end']);
        $deleted = $this->tripWithStops(['departure_at' => $tomorrow, 'estimated_arrival_at' => $tomorrow->copy()->addHours(2)], ['Gone', 'Gone end']);
        $deleted->delete();

        $day = $tomorrow->toDateString();
        $data = $this->getJson($this->api("stops?from={$day}&to={$day}"))->assertOk()->json('data');

        // Mid A has no ETA, so it falls back to its trip's departure (08:00).
        $this->assertSame(['Depot A', 'Mid A', 'Depot B', 'End B', 'End A'], array_column($data, 'location_name'));
        $this->assertSame($a->trip_number, $data[0]['trip']['trip_number']);
        $this->assertArrayHasKey('driver', $data[0]['trip']);
    }

    public function test_board_filters_by_status_trip_status_and_search(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $active = $this->tripWithStops(['status' => TripStatus::InProgress], ['Taguig Hub', 'Calamba Plant']);
        $active->stops()->first()->update(['status' => 'completed']);
        $this->tripWithStops([], ['Manila Port', 'Lipa Yard']);

        $this->getJson($this->api('stops?status=completed'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.location_name', 'Taguig Hub');
        $this->getJson($this->api('stops?trip_status=in_progress'))->assertJsonCount(2, 'data');
        $this->getJson($this->api('stops?search=Lipa'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('stops?search='.$active->trip_number))->assertJsonCount(2, 'data');
    }
}
