<?php

namespace Tests\Feature;

use App\Enums\DriverStatus;
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

class TripDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function schedulePayload(array $overrides = []): array
    {
        $departure = now()->addDay()->setTime(8, 0);

        return [
            'vehicle_id' => Vehicle::factory()->create()->id,
            'driver_id' => Driver::factory()->create()->id,
            'origin' => 'Manila',
            'destination' => 'Batangas City',
            'departure_at' => $departure->toIso8601String(),
            'estimated_arrival_at' => $departure->copy()->addHours(4)->toIso8601String(),
            'stops' => [['location_name' => 'Calamba, Laguna', 'estimated_arrival_at' => $departure->copy()->addHours(2)->toIso8601String()]],
            ...$overrides,
        ];
    }

    public function test_scheduling_a_trip_creates_stops_an_assignment_and_schedules_the_order(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $order = Order::factory()->create();

        $response = $this->postJson($this->api('trips'), $this->schedulePayload(['order_id' => $order->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.trip_number', 'TRIP-'.now()->year.'-0001')
            ->assertJsonCount(3, 'data.stops')
            ->assertJsonPath('data.stops.0.type', 'pickup')
            ->assertJsonPath('data.stops.1.location_name', 'Calamba, Laguna')
            ->assertJsonPath('data.stops.2.type', 'dropoff')
            ->assertJsonCount(1, 'data.assignments');

        $order->refresh();
        $this->assertSame(OrderStatus::Scheduled, $order->status);
        $this->assertSame($response->json('data.vehicle_id'), $order->vehicle_id);
    }

    public function test_overlapping_bookings_are_rejected(): void
    {
        $this->actingAsRole();
        $existing = Trip::factory()->create();

        $this->postJson($this->api('trips'), $this->schedulePayload([
            'vehicle_id' => $existing->vehicle_id,
            'driver_id' => $existing->driver_id,
            'departure_at' => $existing->departure_at->copy()->addHour()->toIso8601String(),
            'estimated_arrival_at' => $existing->estimated_arrival_at->copy()->addHour()->toIso8601String(),
            'stops' => [],
        ]))->assertStatus(422)->assertJsonValidationErrors(['vehicle_id', 'driver_id']);

        // Back-to-back is fine
        $this->postJson($this->api('trips'), $this->schedulePayload([
            'vehicle_id' => $existing->vehicle_id,
            'driver_id' => $existing->driver_id,
            'departure_at' => $existing->estimated_arrival_at->toIso8601String(),
            'estimated_arrival_at' => $existing->estimated_arrival_at->copy()->addHours(2)->toIso8601String(),
            'stops' => [],
        ]))->assertCreated();
    }

    public function test_unavailable_vehicles_drivers_and_orders_are_rejected(): void
    {
        $this->actingAsRole();

        $this->postJson($this->api('trips'), $this->schedulePayload([
            'vehicle_id' => Vehicle::factory()->create(['status' => VehicleStatus::Maintenance])->id,
            'driver_id' => Driver::factory()->create(['status' => DriverStatus::Suspended])->id,
        ]))->assertStatus(422)->assertJsonValidationErrors(['vehicle_id', 'driver_id']);

        $this->postJson($this->api('trips'), $this->schedulePayload([
            'driver_id' => Driver::factory()->create(['license_expiry' => now()->toDateString()])->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('driver_id');

        $this->postJson($this->api('trips'), $this->schedulePayload([
            'order_id' => Order::factory()->create(['status' => OrderStatus::Delivered])->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('order_id');

        $this->postJson($this->api('trips'), $this->schedulePayload([
            'estimated_arrival_at' => now()->toIso8601String(),
        ]))->assertStatus(422)->assertJsonValidationErrors('estimated_arrival_at');
    }

    public function test_availability_lists_only_free_resources(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $busy = Trip::factory()->create();
        $freeVehicle = Vehicle::factory()->create();
        Vehicle::factory()->create(['status' => VehicleStatus::Inactive]);

        $data = $this->getJson($this->api('trips/availability?'.http_build_query([
            'departure_at' => $busy->departure_at->toIso8601String(),
            'estimated_arrival_at' => $busy->estimated_arrival_at->toIso8601String(),
        ])))->assertOk()->json('data');

        $this->assertSame([$freeVehicle->id], array_column($data['vehicles'], 'id'));
        $this->assertNotContains($busy->driver_id, array_column($data['drivers'], 'id'));
    }

    public function test_full_dispatch_lifecycle_updates_order_vehicle_and_driver(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $order = Order::factory()->create();
        $tripId = $this->postJson($this->api('trips'), $this->schedulePayload(['order_id' => $order->id]))->json('data.id');

        $this->patchJson($this->api("trips/{$tripId}/status"), ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->patchJson($this->api("trips/{$tripId}/status"), ['status' => 'dispatched', 'notes' => 'Safe driving'])
            ->assertOk()->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.dispatch_notes', 'Safe driving');

        $this->patchJson($this->api("trips/{$tripId}/status"), ['status' => 'in_progress'])->assertOk();
        $trip = Trip::find($tripId);
        $this->assertSame(VehicleStatus::OnTrip, $trip->vehicle->status);
        $this->assertSame(DriverStatus::OnTrip, $trip->driver->status);
        $this->assertSame(OrderStatus::InTransit, $order->fresh()->status);

        $this->patchJson($this->api("trips/{$tripId}/status"), ['status' => 'completed'])
            ->assertOk()->assertJsonPath('data.stops.1.status', 'completed');
        $trip->refresh();
        $this->assertNotNull($trip->actual_arrival_at);
        $this->assertSame(VehicleStatus::Available, $trip->vehicle->status);
        $this->assertSame(DriverStatus::Available, $trip->driver->status);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_cancelling_a_trip_returns_the_order_to_pending(): void
    {
        $this->actingAsRole();
        $order = Order::factory()->create();
        $tripId = $this->postJson($this->api('trips'), $this->schedulePayload(['order_id' => $order->id]))->json('data.id');

        $this->patchJson($this->api("trips/{$tripId}/status"), ['status' => 'cancelled', 'notes' => 'Weather'])->assertOk();
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($order->fresh()->vehicle_id);
    }

    public function test_trip_can_be_reassigned_and_history_is_kept(): void
    {
        $this->actingAsRole();
        $trip = Trip::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $driver = Driver::factory()->create();

        $this->postJson($this->api("trips/{$trip->id}/reassign"), ['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'reason' => 'Breakdown'])
            ->assertOk()->assertJsonPath('data.vehicle_id', $vehicle->id)->assertJsonPath('data.driver_id', $driver->id);

        $this->assertDatabaseHas('dispatch_assignments', ['trip_id' => $trip->id, 'vehicle_id' => $vehicle->id, 'reason' => 'Breakdown', 'released_at' => null]);

        $conflict = Trip::factory()->create(['departure_at' => $trip->departure_at, 'estimated_arrival_at' => $trip->estimated_arrival_at]);
        $this->postJson($this->api("trips/{$conflict->id}/reassign"), ['vehicle_id' => $vehicle->id, 'driver_id' => $conflict->driver_id])
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        $trip->update(['status' => TripStatus::InProgress]);
        $this->postJson($this->api("trips/{$trip->id}/reassign"), ['vehicle_id' => $conflict->vehicle_id, 'driver_id' => $conflict->driver_id])
            ->assertStatus(422);
    }

    public function test_trips_list_supports_dispatch_scope_and_filters(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Trip::factory()->create(['status' => TripStatus::Scheduled]);
        Trip::factory()->create(['status' => TripStatus::Completed]);
        Trip::factory()->create(['status' => TripStatus::Dispatched]);

        $this->getJson($this->api('trips?scope=dispatch'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->api('trips?status=completed'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('trips/summary'))->assertJsonFragment(['status' => 'dispatched', 'count' => 1]);
    }

    public function test_itinerary_stops_can_be_added_reordered_updated_and_removed(): void
    {
        $this->actingAsRole();
        $tripId = $this->postJson($this->api('trips'), $this->schedulePayload())->json('data.id');

        $added = $this->postJson($this->api("trips/{$tripId}/stops"), ['location_name' => 'Sto. Tomas'])
            ->assertCreated()->assertJsonPath('data.sequence', 3)->json('data.id');

        $stops = $this->getJson($this->api("trips/{$tripId}/stops"))->assertJsonCount(4, 'data')->json('data');
        $this->assertSame(['pickup', 'stop', 'stop', 'dropoff'], array_column($stops, 'type'));
        $calamba = $stops[1]['id'];

        $this->putJson($this->api("trips/{$tripId}/stops/reorder"), ['stop_ids' => [$added]])->assertStatus(422);
        $reordered = $this->putJson($this->api("trips/{$tripId}/stops/reorder"), ['stop_ids' => [$added, $calamba]])->assertOk()->json('data');
        $this->assertSame([$added, $calamba], [$reordered[1]['id'], $reordered[2]['id']]);
        $this->assertSame([1, 2, 3, 4], array_column($reordered, 'sequence'));

        $this->patchJson($this->api("trips/{$tripId}/stops/{$added}"), ['notes' => 'Gate 3'])->assertOk()->assertJsonPath('data.notes', 'Gate 3');
        $this->patchJson($this->api("trips/{$tripId}/stops/{$added}"), ['status' => 'arrived'])->assertStatus(422);

        $this->deleteJson($this->api("trips/{$tripId}/stops/{$stops[0]['id']}"))->assertStatus(422);
        $this->deleteJson($this->api("trips/{$tripId}/stops/{$added}"))->assertOk();
        $this->assertSame([1, 2, 3], array_column($this->getJson($this->api("trips/{$tripId}/stops"))->json('data'), 'sequence'));

        $other = Trip::factory()->create();
        $this->deleteJson($this->api("trips/{$other->id}/stops/{$calamba}"))->assertNotFound();
    }

    public function test_scheduled_trip_can_be_edited_but_not_after_it_starts(): void
    {
        $this->actingAsRole();
        $trip = Trip::factory()->create();

        $this->patchJson($this->api("trips/{$trip->id}"), ['destination' => 'Lipa City', 'distance_km' => 98.5])
            ->assertOk()->assertJsonPath('data.destination', 'Lipa City');

        $trip->update(['status' => TripStatus::InProgress]);
        $this->patchJson($this->api("trips/{$trip->id}"), ['destination' => 'Elsewhere'])->assertStatus(422);
    }

    public function test_viewers_cannot_schedule_or_dispatch(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $trip = Trip::factory()->create();

        $this->postJson($this->api('trips'), $this->schedulePayload())->assertForbidden();
        $this->patchJson($this->api("trips/{$trip->id}/status"), ['status' => 'dispatched'])->assertForbidden();
        $this->postJson($this->api("trips/{$trip->id}/stops"), ['location_name' => 'X'])->assertForbidden();
    }
}
