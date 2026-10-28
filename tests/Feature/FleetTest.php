<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_manage_vehicles(): void
    {
        $this->actingAsRole(UserRole::Manager);
        $driver = Driver::factory()->create();

        $id = $this->postJson($this->api('vehicles'), [
            'plate_number' => 'ABC-1234', 'vehicle_type' => 'cargo_truck', 'make' => 'Isuzu', 'model' => 'FVM 240',
            'year' => 2023, 'capacity_kg' => 15000, 'fuel_type' => 'diesel', 'assigned_driver_id' => $driver->id,
        ])->assertCreated()->assertJsonPath('data.assigned_driver.full_name', $driver->full_name)->json('data.id');

        $this->postJson($this->api('vehicles'), [
            'plate_number' => 'ABC-1234', 'vehicle_type' => 'spaceship', 'make' => 'X', 'model' => 'Y', 'year' => 1900, 'fuel_type' => 'diesel',
        ])->assertStatus(422)->assertJsonValidationErrors(['plate_number', 'vehicle_type', 'year']);

        $this->patchJson($this->api("vehicles/{$id}"), ['status' => 'maintenance'])->assertOk()->assertJsonPath('data.status', 'maintenance');
        $this->getJson($this->api("vehicles/{$id}"))->assertOk()->assertJsonPath('data.plate_number', 'ABC-1234');
        $this->getJson($this->api('vehicles?status=maintenance'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('vehicles?search=isuzu'))->assertJsonCount(1, 'data');

        $this->deleteJson($this->api("vehicles/{$id}"))->assertOk();
        $this->assertSoftDeleted('vehicles', ['id' => $id]);
    }

    public function test_a_driver_can_only_be_assigned_to_one_vehicle(): void
    {
        $this->actingAsRole();
        $driver = Driver::factory()->create();
        Vehicle::factory()->create(['assigned_driver_id' => $driver->id]);

        $this->postJson($this->api('vehicles'), [
            'plate_number' => 'NEW-0001', 'vehicle_type' => 'closed_van', 'make' => 'Hino', 'model' => '300',
            'year' => 2022, 'fuel_type' => 'diesel', 'assigned_driver_id' => $driver->id,
        ])->assertStatus(422)->assertJsonValidationErrors('assigned_driver_id');
    }

    public function test_vehicle_with_open_trips_cannot_be_deleted(): void
    {
        $this->actingAsRole();
        $trip = Trip::factory()->create(['status' => TripStatus::Scheduled]);

        $this->deleteJson($this->api("vehicles/{$trip->vehicle_id}"))->assertStatus(422);
        $this->deleteJson($this->api("drivers/{$trip->driver_id}"))->assertStatus(422);
    }

    public function test_manager_can_manage_drivers(): void
    {
        $this->actingAsRole(UserRole::Manager);

        $response = $this->postJson($this->api('drivers'), [
            'full_name' => 'Ramon Santos', 'phone' => '0917 123 4567', 'email' => 'ramon@example.com',
            'license_number' => 'N01-23-456789', 'license_type' => 'professional', 'license_expiry' => now()->addYear()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.employee_code', 'DRV-0001')->assertJsonPath('data.status', 'available');
        $id = $response->json('data.id');

        $this->postJson($this->api('drivers'), [
            'full_name' => 'Dup', 'phone' => '1', 'license_number' => 'N01-23-456789', 'license_type' => 'pro', 'license_expiry' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors(['license_number', 'license_type', 'license_expiry']);

        $this->putJson($this->api("drivers/{$id}"), [
            'full_name' => 'Ramon S. Santos', 'phone' => '0917 123 4567', 'license_number' => 'N01-23-456789',
            'license_type' => 'professional', 'license_expiry' => now()->addYears(2)->toDateString(), 'status' => 'on_leave',
        ])->assertOk()->assertJsonPath('data.status', 'on_leave');

        $this->getJson($this->api('drivers?status=on_leave'))->assertJsonCount(1, 'data');
        $this->getJson($this->api("drivers/{$id}"))->assertOk()->assertJsonPath('data.trips_count', 0);
        $this->getJson($this->api('drivers/summary'))->assertOk()->assertJsonFragment(['status' => 'on_leave', 'count' => 1]);

        $this->deleteJson($this->api("drivers/{$id}"))->assertOk();
        $this->assertSoftDeleted('drivers', ['id' => $id]);
    }

    public function test_dispatchers_cannot_change_fleet_master_data(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create();
        $driver = Driver::factory()->create();

        $this->getJson($this->api('vehicles'))->assertOk();
        $this->patchJson($this->api("vehicles/{$vehicle->id}"), ['status' => 'inactive'])->assertForbidden();
        $this->deleteJson($this->api("drivers/{$driver->id}"))->assertForbidden();
        $this->postJson($this->api('drivers'), [])->assertForbidden();
    }
}
