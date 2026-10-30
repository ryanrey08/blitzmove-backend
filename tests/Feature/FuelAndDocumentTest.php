<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentFile;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\FuelRecord;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FuelAndDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_fuel_records_compute_cost_and_summarise(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create();

        $id = $this->postJson($this->api('fuel-records'), [
            'vehicle_id' => $vehicle->id, 'fueled_at' => now()->subHour()->toIso8601String(), 'fuel_type' => 'diesel',
            'liters' => 50, 'price_per_liter' => 62.5, 'odometer_km' => 45000, 'station' => 'Petron SLEX',
            'total_cost' => 1, // ignored; always computed
        ])->assertCreated()->assertJsonPath('data.total_cost', 3125)->json('data.id');

        $this->patchJson($this->api("fuel-records/{$id}"), ['liters' => 40])->assertOk()->assertJsonPath('data.total_cost', 2500);

        FuelRecord::factory()->create(['vehicle_id' => $vehicle->id, 'liters' => 10, 'price_per_liter' => 60, 'total_cost' => 600]);
        $this->getJson($this->api("fuel-records/summary?vehicle_id={$vehicle->id}"))
            ->assertOk()->assertJsonPath('data.entries', 2)->assertJsonPath('data.total_cost', 3100);
        $this->getJson($this->api("fuel-records?vehicle_id={$vehicle->id}"))->assertJsonCount(2, 'data');

        $this->postJson($this->api('fuel-records'), ['vehicle_id' => 999, 'liters' => -1, 'fueled_at' => now()->addDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors(['vehicle_id', 'liters', 'fueled_at', 'fuel_type', 'price_per_liter']);

        $this->deleteJson($this->api("fuel-records/{$id}"))->assertOk();
        $this->assertSoftDeleted('fuel_records', ['id' => $id]);
    }

    public function test_fuel_transaction_fields_receipt_photo_and_odometer_sync(): void
    {
        Storage::fake('local');
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create(['odometer_km' => 50000]);

        $id = $this->postJson($this->api('fuel-records'), [
            'vehicle_id' => $vehicle->id, 'fueled_at' => now()->subHour()->toIso8601String(), 'fuel_type' => 'diesel',
            'liters' => 45.5, 'price_per_liter' => 60, 'odometer_km' => 51200, 'station' => 'Shell Calamba',
            'payment_method' => 'fuel_card', 'receipt_number' => 'OR-1', 'reference_number' => 'PO-77',
        ])->assertCreated()
            ->assertJsonPath('data.total_cost', 2730)
            ->assertJsonPath('data.payment_method', 'fuel_card')
            ->assertJsonPath('data.reference_number', 'PO-77')
            ->assertJsonPath('data.receipt', null)
            ->json('data.id');
        $this->assertSame(51200, $vehicle->fresh()->odometer_km);

        // An older reading never winds the vehicle's odometer back.
        $this->postJson($this->api('fuel-records'), [
            'vehicle_id' => $vehicle->id, 'fueled_at' => now()->subDays(3)->toIso8601String(), 'fuel_type' => 'diesel',
            'liters' => 10, 'price_per_liter' => 60, 'odometer_km' => 49000,
        ])->assertCreated();
        $this->assertSame(51200, $vehicle->fresh()->odometer_km);

        $this->postJson($this->api('fuel-records'), ['vehicle_id' => $vehicle->id, 'payment_method' => 'barter'])
            ->assertStatus(422)->assertJsonValidationErrors(['payment_method']);

        $this->post($this->api("fuel-records/{$id}/receipt"), ['receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['receipt']);
        $this->post($this->api("fuel-records/{$id}/receipt"), ['receipt' => UploadedFile::fake()->create('receipt.jpg', 200, 'image/jpeg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.receipt', "fuel-records/{$id}/receipt");
        $path = FuelRecord::find($id)->receipt_path;
        Storage::disk('local')->assertExists($path);
        $this->get($this->api("fuel-records/{$id}/receipt"))->assertOk();
        $this->getJson($this->api("fuel-records/{$id}"))->assertJsonPath('data.receipt', "fuel-records/{$id}/receipt");

        $this->getJson($this->api('fuel-records/stations'))->assertOk()->assertJsonPath('data.0', 'Shell Calamba');
        $this->getJson($this->api('fuel-records?search=PO-77'))->assertJsonCount(1, 'data');

        $this->deleteJson($this->api("fuel-records/{$id}/receipt"))->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->get($this->api("fuel-records/{$id}/receipt"), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_vehicle_document_upload_download_and_expiry_status(): void
    {
        Storage::fake('local');
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create();

        $response = $this->post($this->api('documents'), [
            'owner_type' => 'vehicle', 'owner_id' => $vehicle->id, 'document_type' => 'registration',
            'title' => 'Certificate of Registration', 'document_number' => 'CR-2023-00123', 'issuing_authority' => 'LTO',
            'issued_at' => now()->subYear()->toDateString(), 'expires_at' => now()->addDays(10)->toDateString(),
            'files' => [
                UploadedFile::fake()->create('cr.pdf', 120, 'application/pdf'),
                UploadedFile::fake()->create('cr-back.jpg', 200, 'image/jpeg'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.expiry_status', 'expiring_soon')
            ->assertJsonPath('data.issuing_authority', 'LTO')
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.files_count', 2)
            ->assertJsonPath('data.files.0.original_name', 'cr.pdf');

        $id = $response->json('data.id');
        $first = DocumentFile::findOrFail($response->json('data.files.0.id'));
        Storage::disk('local')->assertExists($first->file_path);

        $this->get($this->api("documents/vehicle/{$id}/download"))->assertOk()->assertDownload('cr.pdf');
        $this->get($this->api($response->json('data.files.1.path')))->assertOk();
        $this->get($this->api($response->json('data.files.0.path').'?download=1'))->assertOk()->assertDownload('cr.pdf');

        // Add one more file on its own, then remove the first one.
        $this->post($this->api("documents/vehicle/{$id}/files"), ['file' => UploadedFile::fake()->create('cr-2027.pdf', 80, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.original_name', 'cr-2027.pdf');
        $this->deleteJson($this->api("documents/vehicle/{$id}/files/{$first->id}"))->assertOk();
        Storage::disk('local')->assertMissing($first->file_path);
        $this->getJson($this->api("documents/vehicle/{$id}"))->assertJsonPath('data.files_count', 2)->assertJsonPath('data.files.1.original_name', 'cr-2027.pdf');

        // Edit details via method spoofing (multipart PUT)
        $this->post($this->api("documents/vehicle/{$id}"), ['_method' => 'PUT', 'title' => 'CR 2027'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.title', 'CR 2027');

        $this->getJson($this->api('documents?owner_type=vehicle&search=LTO'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('documents?owner_type=vehicle&expiry_status=expiring_soon'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('documents?owner_type=vehicle&expiry_status=expired'))->assertJsonCount(0, 'data');

        $this->deleteJson($this->api("documents/vehicle/{$id}"))->assertOk();
        $this->getJson($this->api("documents/vehicle/{$id}"))->assertNotFound();
    }

    public function test_document_files_are_validated_and_scoped_to_their_document(): void
    {
        Storage::fake('local');
        $this->actingAsRole(UserRole::Dispatcher);
        $a = VehicleDocument::create(['vehicle_id' => Vehicle::factory()->create()->id, 'document_type' => 'permit', 'title' => 'Permit A']);
        $b = VehicleDocument::create(['vehicle_id' => Vehicle::factory()->create()->id, 'document_type' => 'permit', 'title' => 'Permit B']);

        $this->post($this->api("documents/vehicle/{$a->id}/files"), ['file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Each file can be up to 10 MB.');
        $this->post($this->api("documents/vehicle/{$a->id}/files"), ['file' => UploadedFile::fake()->create('sheet.docx', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Files must be PDF, JPG or PNG.');

        $fileId = $this->post($this->api("documents/vehicle/{$a->id}/files"), ['file' => UploadedFile::fake()->create('permit.png', 50, 'image/png')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->get($this->api("documents/vehicle/{$b->id}/files/{$fileId}"), ['Accept' => 'application/json'])->assertNotFound();
        $this->deleteJson($this->api("documents/vehicle/{$b->id}/files/{$fileId}"))->assertNotFound();
        $this->get($this->api("documents/driver/{$a->id}/files/{$fileId}"), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_driver_documents_validate_type_and_file(): void
    {
        Storage::fake('local');
        $this->actingAsRole();
        $driver = Driver::factory()->create();

        $this->post($this->api('documents'), [
            'owner_type' => 'driver', 'owner_id' => $driver->id, 'document_type' => 'registration', 'title' => 'Wrong type',
            'files' => [UploadedFile::fake()->create('virus.exe', 10)],
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['document_type', 'files.0']);

        $this->postJson($this->api('documents'), [
            'owner_type' => 'driver', 'owner_id' => $driver->id, 'document_type' => 'license', 'title' => 'License',
            'expires_at' => now()->subDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.expiry_status', 'expired')->assertJsonPath('data.has_file', false);

        $this->getJson($this->api("documents?owner_type=driver&owner_id={$driver->id}"))->assertJsonCount(1, 'data');
        $this->get($this->api('documents/driver/1/download'), ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_viewers_can_read_but_not_upload_documents(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $vehicle = Vehicle::factory()->create();

        $this->getJson($this->api('documents'))->assertOk();
        $this->postJson($this->api('documents'), ['owner_type' => 'vehicle', 'owner_id' => $vehicle->id])->assertForbidden();
        $this->postJson($this->api('fuel-records'), [])->assertForbidden();
        $record = FuelRecord::factory()->create(['vehicle_id' => $vehicle->id]);
        $this->postJson($this->api("fuel-records/{$record->id}/receipt"), [])->assertForbidden();
    }

    public function test_detail_endpoints_include_related_records(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $driver = Driver::factory()->create();
        $vehicle = Vehicle::factory()->create();
        $record = FuelRecord::factory()->create(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);

        $this->getJson($this->api("fuel-records/{$record->id}"))->assertOk()
            ->assertJsonPath('data.vehicle.plate_number', $vehicle->plate_number)
            ->assertJsonPath('data.vehicle.photos', [])
            ->assertJsonPath('data.driver.full_name', $driver->full_name);
        $this->getJson($this->api('fuel-records/999999'))->assertNotFound();

        $vehicleDoc = VehicleDocument::create(['vehicle_id' => $vehicle->id, 'document_type' => 'registration', 'title' => 'OR/CR']);
        $driverDoc = DriverDocument::create(['driver_id' => $driver->id, 'document_type' => 'license', 'title' => 'License']);
        $this->getJson($this->api("documents/vehicle/{$vehicleDoc->id}"))->assertOk()
            ->assertJsonPath('data.vehicle.id', $vehicle->id)->assertJsonPath('data.files', []);
        $this->getJson($this->api("documents/driver/{$driverDoc->id}"))->assertOk()
            ->assertJsonPath('data.driver.id', $driver->id);
        $this->getJson($this->api('documents/driver/999999'))->assertNotFound();
    }
}
