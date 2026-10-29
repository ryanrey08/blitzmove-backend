<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DispatchTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_trip_is_dispatched_with_time_notes_and_creator(): void
    {
        $user = $this->actingAsRole(UserRole::Dispatcher);
        $trip = Trip::factory()->create(['created_by' => $user->id]);
        $at = now()->subMinutes(30)->startOfMinute();

        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched', 'notes' => 'Drive safe', 'dispatched_at' => $at->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.dispatch_notes', 'Drive safe')
            ->assertJsonPath('data.dispatched_by.id', $user->id)
            ->assertJsonPath('data.created_by.id', $user->id);

        $this->assertTrue($trip->fresh()->dispatched_at->equalTo($at));
    }

    public function test_a_trip_cannot_be_dispatched_twice(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $trip = Trip::factory()->create();

        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched'])->assertOk();
        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status')
            ->assertJsonPath('message', "Trip {$trip->trip_number} has already been dispatched.");
    }

    public function test_vehicle_and_driver_can_be_changed_while_dispatching(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $trip = Trip::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $driver = Driver::factory()->create();

        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched', 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'dispatched')
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonPath('data.driver_id', $driver->id);

        $this->assertDatabaseHas('dispatch_assignments', ['trip_id' => $trip->id, 'vehicle_id' => $vehicle->id, 'reason' => 'Changed at dispatch', 'released_at' => null]);
    }

    public function test_dispatch_input_is_validated(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $trip = Trip::factory()->create();
        $busy = Trip::factory()->create();

        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched', 'dispatched_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('dispatched_at');
        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched', 'vehicle_id' => Vehicle::factory()->create()->id])
            ->assertStatus(422)->assertJsonValidationErrors('driver_id');

        // A vehicle already booked in the same window is refused and the trip stays scheduled.
        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched', 'vehicle_id' => $busy->vehicle_id, 'driver_id' => $trip->driver_id])
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->assertSame('scheduled', $trip->fresh()->status->value);
    }

    public function test_only_transitions_allowed_by_the_status_are_accepted(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);

        foreach ([
            [TripStatus::InProgress, 'dispatched'],
            [TripStatus::Completed, 'dispatched'],
            [TripStatus::Cancelled, 'dispatched'],
            [TripStatus::Scheduled, 'in_progress'],
            [TripStatus::Scheduled, 'completed'],
            [TripStatus::Completed, 'cancelled'],
        ] as [$from, $to]) {
            $trip = Trip::factory()->create(['status' => $from]);
            $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => $to])
                ->assertStatus(422)->assertJsonValidationErrors('status');
            $this->assertSame($from, $trip->fresh()->status, "{$from->value} -> {$to} should be rejected");
        }

        $trip = Trip::factory()->create(['status' => TripStatus::Dispatched]);
        $this->getJson($this->api("trips/{$trip->id}"))->assertJsonPath('allowed_transitions', null)->assertJsonPath('data.allowed_transitions', ['in_progress', 'scheduled', 'cancelled']);
    }
}
