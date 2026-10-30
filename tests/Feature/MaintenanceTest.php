<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Vehicle $vehicle, array $overrides = []): array
    {
        return [
            'vehicle_id' => $vehicle->id, 'service_type' => 'oil_change', 'category' => 'preventive', 'status' => 'scheduled',
            'scheduled_date' => today()->addDays(3)->toDateString(), 'estimated_cost' => 4500, 'assigned_to' => 'Rico Mendoza',
            'service_provider' => 'Isuzu Calamba Service Center', 'reminder_days' => 3,
            'checklist' => [['item' => 'Engine oil and filter', 'done' => true], ['item' => 'Brake inspection', 'done' => false]],
            'parts' => [['name' => 'Oil filter', 'quantity' => 1, 'unit_cost' => 650], ['name' => 'Engine oil (L)', 'quantity' => 7, 'unit_cost' => 420]],
            ...$overrides,
        ];
    }

    public function test_creates_lists_and_filters_maintenance(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create(['plate_number' => 'GHI-9012']);
        MaintenanceRecord::factory()->completed(today()->subMonths(3)->toDateString())->create(['vehicle_id' => $vehicle->id, 'reference_number' => 'MNT-OLD-1']);

        $record = $this->postJson($this->api('maintenance-records'), $this->payload($vehicle))
            ->assertCreated()
            ->assertJsonPath('data.reference_number', 'MNT-'.now()->format('Y').'-0001')
            ->assertJsonPath('data.display_status', 'due_soon')
            ->assertJsonPath('data.parts_total', 3590)
            ->assertJsonPath('data.checklist.0.done', true)
            ->assertJsonPath('data.last_service_date', today()->subMonths(3)->toDateString())
            ->assertJsonPath('data.vehicle.plate_number', 'GHI-9012')
            ->json('data');

        MaintenanceRecord::factory()->create(['scheduled_date' => today()->subDays(2), 'service_type' => 'brake_service']);
        MaintenanceRecord::factory()->create(['scheduled_date' => today()->addDays(20)]);

        $this->getJson($this->api('maintenance-records'))->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.display_status', 'overdue')
            ->assertJsonPath('data.3.status', 'completed');
        $this->getJson($this->api('maintenance-records?status=overdue'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('maintenance-records?status=due_soon,scheduled'))->assertJsonCount(2, 'data');
        $this->getJson($this->api('maintenance-records?search=ghi-9012'))->assertJsonCount(2, 'data');
        $this->getJson($this->api('maintenance-records?search=brake'))->assertJsonCount(1, 'data');
        $this->getJson($this->api("maintenance-records?vehicle_id={$vehicle->id}&from=".today()->toDateString()))->assertJsonCount(1, 'data');

        $this->getJson($this->api('maintenance-records/summary'))->assertOk()
            ->assertJsonPath('data.total.count', 4)
            ->assertJsonPath('data.upcoming.count', 2)
            ->assertJsonPath('data.overdue.count', 1)
            ->assertJsonPath('data.in_progress.count', 0);

        $this->getJson($this->api('maintenance-records/options'))
            ->assertJsonPath('data.service_providers.0', 'Isuzu Calamba Service Center')
            ->assertJsonPath('data.assignees.0', 'Rico Mendoza');
        $this->getJson($this->api('maintenance-records/next-number'))->assertJsonPath('data.reference_number', 'MNT-'.now()->format('Y').'-0002');
        $this->getJson($this->api("maintenance-records/{$record['id']}"))->assertOk()->assertJsonPath('data.files', []);
    }

    public function test_validates_maintenance(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create();
        MaintenanceRecord::factory()->create(['reference_number' => 'MNT-TAKEN']);

        $this->postJson($this->api('maintenance-records'), [
            'vehicle_id' => 999, 'service_type' => 'wash', 'status' => 'done', 'estimated_cost' => -5, 'odometer_km' => -1,
            'reference_number' => 'mnt-taken', 'reminder_days' => 2, 'parts' => [['name' => '', 'quantity' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'vehicle_id', 'service_type', 'category', 'status', 'scheduled_date', 'estimated_cost', 'odometer_km',
            'reference_number', 'reminder_days', 'parts.0.name', 'parts.0.quantity', 'parts.0.unit_cost',
        ]);

        // Completed work needs the day it was done, not in the future.
        $this->postJson($this->api('maintenance-records'), $this->payload($vehicle, ['status' => 'completed']))
            ->assertStatus(422)->assertJsonValidationErrors(['service_date']);
        $this->postJson($this->api('maintenance-records'), $this->payload($vehicle, ['status' => 'completed', 'service_date' => today()->addDay()->toDateString()]))
            ->assertStatus(422)->assertJsonValidationErrors(['service_date']);
    }

    public function test_status_changes_move_the_vehicle_in_and_out_of_service(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $vehicle = Vehicle::factory()->create(['status' => 'available', 'odometer_km' => 50000]);

        $id = $this->postJson($this->api('maintenance-records'), $this->payload($vehicle, ['status' => 'in_progress', 'odometer_km' => 51000]))
            ->assertCreated()->assertJsonPath('data.display_status', 'in_progress')->json('data.id');
        $this->assertSame('maintenance', $vehicle->fresh()->status->value);
        $this->assertSame(51000, $vehicle->fresh()->odometer_km);
        $this->assertNotNull(MaintenanceRecord::find($id)->started_at);

        $this->patchJson($this->api("maintenance-records/{$id}"), ['status' => 'completed', 'service_date' => today()->toDateString(), 'actual_cost' => 5200])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.actual_cost', 5200);
        $this->assertSame('available', $vehicle->fresh()->status->value);
        $this->getJson($this->api('maintenance-records/summary'))->assertJsonPath('data.completed_this_month.count', 1);

        // A vehicle someone took out of service by hand stays that way.
        $vehicle->update(['status' => 'inactive']);
        $this->postJson($this->api('maintenance-records'), $this->payload($vehicle, ['status' => 'in_progress']))->assertCreated();
        $this->assertSame('inactive', $vehicle->fresh()->status->value);
    }

    public function test_attachments_and_permissions(): void
    {
        Storage::fake('local');
        $this->actingAsRole(UserRole::Dispatcher);
        $record = MaintenanceRecord::factory()->create();

        $this->post($this->api("maintenance-records/{$record->id}/files"), ['file' => UploadedFile::fake()->create('job.exe', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['file']);
        $file = $this->post($this->api("maintenance-records/{$record->id}/files"), ['file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $this->assertSame("maintenance-records/{$record->id}/files/{$file['id']}", $file['path']);
        $this->get($this->api($file['path']))->assertOk();
        $this->getJson($this->api("maintenance-records/{$record->id}"))->assertJsonCount(1, 'data.files');
        $this->deleteJson($this->api($file['path']))->assertOk();
        $this->assertDatabaseCount('document_files', 0);

        $this->deleteJson($this->api("maintenance-records/{$record->id}"))->assertOk();
        $this->assertSoftDeleted('maintenance_records', ['id' => $record->id]);

        $this->actingAsRole(UserRole::Viewer);
        $this->getJson($this->api('maintenance-records'))->assertOk();
        $this->postJson($this->api('maintenance-records'), [])->assertForbidden();
    }
}
