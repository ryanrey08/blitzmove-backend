<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleProfileTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'plate_number' => 'ABC-1234',
            'vehicle_type' => 'closed_van',
            'make' => 'Fuso',
            'model' => 'Canter',
            'year' => 2023,
            'color' => 'White',
            'body_type' => 'closed_van',
            'wheels' => 6,
            'vin' => 'JLFEB71P2KJ123456',
            'engine_number' => '4P10-123456',
            'registration_expiry' => '2026-12-31',
            'insurance_expiry' => '2026-12-31',
            'home_depot' => 'Main Warehouse',
            'gps_device_id' => 'GPS-00123',
            'odometer_km' => 25340,
            'acquired_at' => '2023-01-15',
            'capacity_kg' => 5000,
            'cargo_volume_m3' => 40,
            'length_m' => 6.2,
            'width_m' => 2.1,
            'height_m' => 2.3,
            'fuel_type' => 'diesel',
            'fuel_tank_l' => 100,
            'fuel_economy_kml' => 8.5,
            'emission_standard' => 'Euro 4',
            'features' => ['gps_tracker', 'dash_camera'],
            'notes' => 'Standard closed van for general cargo.',
            ...$overrides,
        ];
    }

    public function test_vehicle_is_created_with_profile_and_expiry_documents(): void
    {
        $this->actingAsRole(UserRole::Manager);

        $id = $this->postJson($this->api('vehicles'), $this->payload())->assertCreated()
            ->assertJsonPath('data.color', 'White')
            ->assertJsonPath('data.wheels', 6)
            ->assertJsonPath('data.length_m', 6.2)
            ->assertJsonPath('data.features', ['gps_tracker', 'dash_camera'])
            ->assertJsonPath('data.registration_expiry', '2026-12-31')
            ->assertJsonPath('data.insurance_expiry', '2026-12-31')
            ->json('data.id');

        $this->assertDatabaseHas('vehicle_documents', ['vehicle_id' => $id, 'document_type' => 'registration', 'title' => 'Registration']);

        // Updating the expiry updates the current document instead of adding another.
        $this->putJson($this->api("vehicles/{$id}"), $this->payload(['registration_expiry' => '2027-06-30']))->assertOk()
            ->assertJsonPath('data.registration_expiry', '2027-06-30');
        $this->assertSame(1, Vehicle::find($id)->documents()->where('document_type', 'registration')->count());
    }

    public function test_profile_fields_are_validated_and_identifiers_stay_unique(): void
    {
        $this->actingAsRole(UserRole::Manager);
        $this->postJson($this->api('vehicles'), $this->payload())->assertCreated();

        $this->postJson($this->api('vehicles'), $this->payload(['vin' => 'OTHER-VIN', 'wheels' => 5, 'features' => ['jetpack'], 'emission_standard' => 'Euro 9', 'acquired_at' => now()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate_number', 'gps_device_id', 'wheels', 'features.0', 'emission_standard', 'acquired_at']);
    }

    public function test_photos_upload_list_cover_and_delete_with_a_limit_of_five(): void
    {
        Storage::fake('local');
        $this->actingAsRole(UserRole::Manager);
        $vehicle = Vehicle::factory()->create();

        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->post($this->api("vehicles/{$vehicle->id}/photos"), ['photo' => UploadedFile::fake()->create("truck{$i}.jpg", 200, 'image/jpeg')], ['Accept' => 'application/json'])
                ->assertCreated()->json('data.id');
        }
        $this->post($this->api("vehicles/{$vehicle->id}/photos"), ['photo' => UploadedFile::fake()->create('six.jpg', 200, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->post($this->api("vehicles/{$vehicle->id}/photos"), ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->getJson($this->api("vehicles/{$vehicle->id}"))->assertJsonCount(5, 'data.photos')
            ->assertJsonPath('data.cover_photo', "vehicles/{$vehicle->id}/photos/{$ids[0]}");

        $this->patchJson($this->api("vehicles/{$vehicle->id}/photos/{$ids[3]}/cover"))->assertOk();
        $this->getJson($this->api("vehicles/{$vehicle->id}"))->assertJsonPath('data.photos.0.id', $ids[3]);

        $this->get($this->api("vehicles/{$vehicle->id}/photos/{$ids[0]}"))->assertOk();
        $this->deleteJson($this->api("vehicles/{$vehicle->id}/photos/{$ids[0]}"))->assertOk();
        $this->assertDatabaseMissing('vehicle_photos', ['id' => $ids[0]]);

        // Photos are scoped to their vehicle, and viewers can't change them.
        $other = Vehicle::factory()->create();
        $this->get($this->api("vehicles/{$other->id}/photos/{$ids[1]}"))->assertNotFound();
        $this->actingAsRole(UserRole::Viewer);
        $this->deleteJson($this->api("vehicles/{$vehicle->id}/photos/{$ids[1]}"))->assertForbidden();
    }
}
