<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriverProfileTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'full_name' => 'Juan Cruz',
            'date_of_birth' => '1990-05-01',
            'phone' => '0917 555 1234',
            'email' => 'juan.cruz@example.com',
            'address' => '123 Sampaguita St., Calamba, Laguna',
            'emergency_contact_name' => 'Maria Cruz',
            'emergency_contact_phone' => '0917 000 1111',
            'emergency_contact_relation' => 'Spouse',
            'license_number' => 'N01-23-456789',
            'license_type' => 'professional',
            'license_expiry' => now()->addYear()->toDateString(),
            'license_restrictions' => '1, 2, 3',
            'date_hired' => '2023-01-15',
            ...$overrides,
        ];
    }

    public function test_driver_is_created_with_profile_and_assigned_vehicle(): void
    {
        $this->actingAsRole(UserRole::Manager);
        $vehicle = Vehicle::factory()->create();

        $response = $this->postJson($this->api('drivers'), $this->payload(['assigned_vehicle_id' => $vehicle->id]))->assertCreated()
            ->assertJsonPath('data.date_of_birth', '1990-05-01')
            ->assertJsonPath('data.emergency_contact_name', 'Maria Cruz')
            ->assertJsonPath('data.license_restrictions', '1, 2, 3')
            ->assertJsonPath('data.assigned_vehicle.id', $vehicle->id);
        $this->assertSame($response->json('data.id'), $vehicle->fresh()->assigned_driver_id);

        // Moving the driver to another vehicle frees the first one; a vehicle someone else drives is refused.
        $other = Vehicle::factory()->create();
        $this->patchJson($this->api('drivers/'.$response->json('data.id')), ['assigned_vehicle_id' => $other->id])->assertOk();
        $this->assertNull($vehicle->fresh()->assigned_driver_id);

        $taken = Vehicle::factory()->create(['assigned_driver_id' => Driver::factory()->create()->id]);
        $this->postJson($this->api('drivers'), $this->payload(['license_number' => 'X-1', 'email' => 'x@example.com', 'assigned_vehicle_id' => $taken->id]))
            ->assertStatus(422)->assertJsonValidationErrors('assigned_vehicle_id');
    }

    public function test_profile_rules(): void
    {
        $this->actingAsRole(UserRole::Manager);
        $this->postJson($this->api('drivers'), $this->payload())->assertCreated();

        $this->postJson($this->api('drivers'), $this->payload(['date_of_birth' => now()->subYears(17)->toDateString(), 'emergency_contact_phone' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['license_number', 'email', 'date_of_birth', 'emergency_contact_phone']);
    }

    public function test_photo_upload_replace_and_remove(): void
    {
        Storage::fake('local');
        $this->actingAsRole(UserRole::Manager);
        $driver = Driver::factory()->create();

        $this->post($this->api("drivers/{$driver->id}/photo"), ['photo' => UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg')], ['Accept' => 'application/json'])->assertOk();
        $first = $driver->fresh()->photo_path;
        $this->post($this->api("drivers/{$driver->id}/photo"), ['photo' => UploadedFile::fake()->create('b.png', 100, 'image/png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing($first);

        $this->getJson($this->api("drivers/{$driver->id}"))->assertJsonPath('data.photo', "drivers/{$driver->id}/photo");
        $this->get($this->api("drivers/{$driver->id}/photo"))->assertOk();
        $this->deleteJson($this->api("drivers/{$driver->id}/photo"))->assertOk();
        $this->get($this->api("drivers/{$driver->id}/photo"))->assertNotFound();

        $this->actingAsRole(UserRole::Viewer);
        $this->post($this->api("drivers/{$driver->id}/photo"), ['photo' => UploadedFile::fake()->create('c.jpg', 10, 'image/jpeg')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_detail_includes_thirty_day_performance(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $driver = Driver::factory()->create();
        Trip::factory()->create(['driver_id' => $driver->id, 'status' => TripStatus::Completed, 'estimated_arrival_at' => now()->subDays(2), 'actual_arrival_at' => now()->subDays(2)->subHour()]);
        Trip::factory()->create(['driver_id' => $driver->id, 'status' => TripStatus::Completed, 'estimated_arrival_at' => now()->subDays(3), 'actual_arrival_at' => now()->subDays(3)->addHour()]);

        $this->getJson($this->api("drivers/{$driver->id}"))->assertOk()
            ->assertJsonPath('performance.completed_trips', 2)
            ->assertJsonPath('performance.on_time_rate', 50);
    }
}
