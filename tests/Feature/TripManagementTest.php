<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $departure = now()->addDays(2)->setTime(9, 0);

        return [
            'reference_no' => 'PO-7781',
            'trip_type' => 'transfer',
            'priority' => 'high',
            'vehicle_id' => Vehicle::factory()->create()->id,
            'driver_id' => Driver::factory()->create()->id,
            'origin' => 'Manila Depot',
            'destination' => 'Lipa City',
            'departure_at' => $departure->toIso8601String(),
            'estimated_arrival_at' => $departure->copy()->addHours(5)->toIso8601String(),
            'stops' => [['location_name' => 'Customer A', 'activity' => 'delivery']],
            ...$overrides,
        ];
    }

    public function test_trip_is_created_with_planning_details_and_co_driver(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $coDriver = Driver::factory()->create();

        $this->postJson($this->api('trips'), $this->payload(['co_driver_id' => $coDriver->id]))
            ->assertCreated()
            ->assertJsonPath('data.reference_no', 'PO-7781')
            ->assertJsonPath('data.trip_type', 'transfer')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.co_driver.id', $coDriver->id)
            ->assertJsonPath('data.stops.1.activity', 'delivery');

        $this->postJson($this->api('trips'), $this->payload(['trip_type' => 'teleport', 'priority' => 'whenever']))
            ->assertStatus(422)->assertJsonValidationErrors(['trip_type', 'priority']);
    }

    public function test_next_trip_number_is_previewed(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Trip::factory()->create(['trip_number' => 'TRIP-'.now()->year.'-0007']);

        $this->getJson($this->api('trips/next-number'))->assertOk()->assertJsonPath('data.trip_number', 'TRIP-'.now()->year.'-0008');
    }

    public function test_type_and_priority_default_when_omitted(): void
    {
        $this->actingAsRole();
        $payload = collect($this->payload())->except(['trip_type', 'priority', 'reference_no'])->all();

        $this->postJson($this->api('trips'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.trip_type', 'delivery')
            ->assertJsonPath('data.priority', 'normal');
    }

    public function test_a_busy_co_driver_is_rejected(): void
    {
        $this->actingAsRole();
        $existing = Trip::factory()->create(['departure_at' => now()->addDays(2)->setTime(8, 0), 'estimated_arrival_at' => now()->addDays(2)->setTime(12, 0)]);

        $this->postJson($this->api('trips'), $this->payload(['co_driver_id' => $existing->driver_id]))
            ->assertStatus(422)->assertJsonValidationErrors('co_driver_id');
    }

    public function test_editing_can_change_resources_details_and_stops(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $trip = Trip::find($this->postJson($this->api('trips'), $this->payload())->json('data.id'));
        $vehicle = Vehicle::factory()->create();

        $this->putJson($this->api("trips/{$trip->id}"), [
            'priority' => 'urgent',
            'reference_no' => 'PO-9000',
            'vehicle_id' => $vehicle->id,
            'stops' => [
                ['location_name' => 'Customer B', 'activity' => 'pickup'],
                ['location_name' => 'Customer C', 'activity' => 'delivery'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.reference_no', 'PO-9000')
            ->assertJsonPath('data.vehicle_id', $vehicle->id)
            ->assertJsonCount(4, 'data.stops')
            ->assertJsonPath('data.stops.2.location_name', 'Customer C')
            ->assertJsonCount(2, 'data.assignments');

        $this->putJson($this->api("trips/{$trip->id}"), ['co_driver_id' => $trip->driver_id])
            ->assertStatus(422)->assertJsonValidationErrors('co_driver_id');
    }

    public function test_deleting_a_trip_soft_deletes_it_and_releases_the_order(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $order = Order::factory()->create();
        $tripId = $this->postJson($this->api('trips'), $this->payload(['order_id' => $order->id]))->json('data.id');
        $this->assertSame(OrderStatus::Scheduled, $order->fresh()->status);

        $this->deleteJson($this->api("trips/{$tripId}"))->assertOk()->assertJsonStructure(['message']);

        $this->assertSoftDeleted('trips', ['id' => $tripId]);
        $this->assertNotNull(Trip::withTrashed()->find($tripId)->deleted_at);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->getJson($this->api('trips'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->api("trips/{$tripId}"))->assertNotFound();
    }

    public function test_in_progress_trips_cannot_be_deleted_and_viewers_cannot_delete(): void
    {
        $this->actingAsRole();
        $active = Trip::factory()->create(['status' => TripStatus::InProgress]);
        $this->deleteJson($this->api("trips/{$active->id}"))->assertStatus(422);
        $this->assertNotSoftDeleted('trips', ['id' => $active->id]);

        $this->actingAsRole(UserRole::Viewer);
        $trip = Trip::factory()->create();
        $this->deleteJson($this->api("trips/{$trip->id}"))->assertForbidden();
    }

    public function test_list_filters_by_type_priority_and_searches_reference(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Trip::factory()->create(['trip_type' => 'pickup', 'priority' => 'urgent', 'reference_no' => 'REF-ABC']);
        Trip::factory()->create(['departure_at' => now()->addDays(5), 'estimated_arrival_at' => now()->addDays(5)->addHours(2)]);

        $this->getJson($this->api('trips?trip_type=pickup'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('trips?priority=urgent,high'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('trips?search=REF-ABC'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference_no', 'REF-ABC');
    }
}
