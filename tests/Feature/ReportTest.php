<?php

namespace Tests\Feature;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Driver;
use App\Models\FuelRecord;
use App\Models\Report;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function trip(Vehicle $vehicle, Driver $driver, string $departure, TripStatus $status, array $overrides = []): Trip
    {
        $at = Carbon::parse($departure);

        return Trip::factory()->create([
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'status' => $status,
            'departure_at' => $at, 'estimated_arrival_at' => $at->copy()->addHours(4), ...$overrides,
        ]);
    }

    private function fuel(Vehicle $vehicle, ?Driver $driver, string $at, int $odometer, float $liters, array $overrides = []): FuelRecord
    {
        return FuelRecord::factory()->create([
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver?->id, 'fueled_at' => $at, 'odometer_km' => $odometer,
            'liters' => $liters, 'price_per_liter' => 60, 'total_cost' => $liters * 60, ...$overrides,
        ]);
    }

    public function test_overview_computes_stats_series_and_previous_period(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $truck = Vehicle::factory()->create();
        $van = Vehicle::factory()->create();
        $ana = Driver::factory()->create();
        $ben = Driver::factory()->create();

        // Odometer: reading in September is the baseline for October's first leg.
        $this->fuel($truck, $ana, '2026-09-25 08:00', 10_000, 50);
        $this->fuel($truck, $ana, '2026-10-05 08:00', 10_400, 40);
        $this->fuel($truck, $ben, '2026-10-12 08:00', 10_700, 30);
        $this->fuel($van, $ben, '2026-10-12 09:00', 5_000, 20); // first reading: no distance yet

        // October: one on time, one late, one in progress, one cancelled.
        $this->trip($truck, $ana, '2026-10-03 08:00', TripStatus::Completed, ['actual_arrival_at' => '2026-10-03 11:00']);
        $this->trip($truck, $ben, '2026-10-10 08:00', TripStatus::Completed, ['actual_arrival_at' => '2026-10-10 14:00']);
        $this->trip($van, $ben, '2026-10-20 08:00', TripStatus::InProgress);
        $this->trip($van, $ana, '2026-10-21 08:00', TripStatus::Cancelled);
        // September (previous period of the same length)
        $this->trip($truck, $ana, '2026-09-20 08:00', TripStatus::Completed, ['actual_arrival_at' => '2026-09-20 11:00']);

        $data = $this->getJson($this->api('reports/overview?from=2026-10-01&to=2026-10-31'))->assertOk()
            ->assertJsonPath('data.period.days', 31)
            ->assertJsonPath('data.previous_period', ['from' => '2026-08-31', 'to' => '2026-09-30'])
            ->assertJsonPath('data.stats.distance_km', ['value' => 700, 'previous' => null])
            ->assertJsonPath('data.stats.fuel_liters', ['value' => 90, 'previous' => 50])
            ->assertJsonPath('data.stats.total_trips', ['value' => 4, 'previous' => 1])
            ->assertJsonPath('data.stats.on_time_rate', ['value' => 50, 'previous' => 100])
            ->assertJsonPath('data.trip_status.0', ['key' => 'delivered', 'label' => 'Delivered', 'count' => 2])
            ->assertJsonPath('data.trip_status.1.count', 0)
            ->assertJsonPath('data.trip_status.3', ['key' => 'delayed', 'label' => 'Delayed', 'count' => 1])
            ->assertJsonPath('data.trip_status.4.count', 1)
            ->assertJsonCount(31, 'data.daily')
            ->json('data');

        $byDay = collect($data['daily'])->keyBy('date');
        $this->assertEquals(400, $byDay['2026-10-05']['distance_km']);
        $this->assertEquals(300, $byDay['2026-10-12']['distance_km']);
        $this->assertEquals(0, $byDay['2026-10-01']['distance_km']);
        $this->assertEquals(50, $byDay['2026-10-12']['fuel_liters']);
        $this->assertEquals(3000, $byDay['2026-10-12']['fuel_cost']);

        // Filters: the driver of the fill-up gets that leg; trips and fuel narrow too.
        $this->getJson($this->api("reports/overview?from=2026-10-01&to=2026-10-31&driver_id={$ben->id}"))->assertOk()
            ->assertJsonPath('data.stats.distance_km.value', 300)
            ->assertJsonPath('data.stats.fuel_liters.value', 50)
            ->assertJsonPath('data.stats.total_trips.value', 2)
            ->assertJsonPath('data.stats.on_time_rate.value', 0);
        $this->getJson($this->api("reports/overview?from=2026-10-01&to=2026-10-31&vehicle_id={$van->id}"))->assertOk()
            ->assertJsonPath('data.stats.distance_km.value', null)
            ->assertJsonPath('data.stats.on_time_rate.value', null)
            ->assertJsonPath('data.daily.0.distance_km', null);
    }

    public function test_fuel_report_computes_stats_breakdowns_and_filters(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $truck = Vehicle::factory()->create(['plate_number' => 'TRK-001']);
        $van = Vehicle::factory()->create(['plate_number' => 'VAN-003']);
        $ana = Driver::factory()->create(['full_name' => 'Ana Cruz']);
        $ben = Driver::factory()->create(['full_name' => 'Ben Reyes']);
        $trip = $this->trip($truck, $ana, '2026-10-05 06:00', TripStatus::Completed, ['origin' => 'Manila', 'destination' => 'Batangas']);

        $this->fuel($truck, $ana, '2026-09-25 08:00', 10_000, 50, ['station' => 'Petron - Bicutan']);
        $this->fuel($truck, $ana, '2026-10-05 08:00', 10_400, 40, ['station' => 'Petron - Bicutan', 'payment_method' => 'cash', 'trip_id' => $trip->id]);
        $this->fuel($truck, $ben, '2026-10-12 08:00', 10_700, 30, ['station' => 'Shell, SLEX', 'payment_method' => 'fuel_card']);
        $this->fuel($van, $ben, '2026-10-12 09:00', 5_000, 20, ['station' => 'Shell, SLEX', 'fuel_type' => 'gasoline']);

        $url = 'reports/fuel?from=2026-10-01&to=2026-10-31';
        $this->getJson($this->api($url))->assertOk()
            ->assertJsonPath('data.stats.fuel_liters', ['value' => 90, 'previous' => 50])
            ->assertJsonPath('data.stats.fuel_cost', ['value' => 5400, 'previous' => 3000])
            ->assertJsonPath('data.stats.transactions', ['value' => 3, 'previous' => 1])
            ->assertJsonPath('data.stats.distance_km', ['value' => 700, 'previous' => null])
            // 700 km over the 70 L bought at the two fill-ups that close a leg
            ->assertJsonPath('data.stats.efficiency_km_per_liter', ['value' => 10, 'previous' => null])
            ->assertJsonCount(31, 'data.daily')
            ->assertJsonPath('data.daily.11', ['date' => '2026-10-12', 'fuel_liters' => 50, 'fuel_cost' => 3000])
            ->assertJsonPath('data.groups.0', ['key' => (string) $truck->id, 'label' => 'TRK-001', 'liters' => 70, 'cost' => 4200, 'transactions' => 2])
            ->assertJsonPath('data.groups.1.label', 'VAN-003')
            ->assertJsonPath('data.fuel_types.1', ['key' => 'gasoline', 'label' => 'Gasoline', 'liters' => 20, 'cost' => 1200, 'transactions' => 1])
            ->assertJsonPath('data.stations.0', ['key' => 'Shell, SLEX', 'label' => 'Shell, SLEX', 'liters' => 50, 'cost' => 3000, 'transactions' => 2])
            ->assertJsonPath('data.routes', [['origin' => 'Manila', 'destination' => 'Batangas']]);

        $this->getJson($this->api("{$url}&group_by=date"))->assertOk()
            ->assertJsonPath('data.groups.0.key', '2026-10-01')
            ->assertJsonPath('data.groups.0.label', 'Oct 1 – 7')
            ->assertJsonPath('data.groups.1.label', 'Oct 8 – 14')
            ->assertJsonPath('data.groups.1.liters', 50);
        $this->getJson($this->api("{$url}&group_by=driver"))->assertJsonPath('data.groups.0.label', 'Ben Reyes');

        // Filters narrow the stats; distance and efficiency follow the fill-ups that match.
        $this->getJson($this->api($url.'&station='.urlencode('Shell, SLEX')))->assertOk()
            ->assertJsonPath('data.stats.fuel_liters.value', 50)
            ->assertJsonPath('data.stats.distance_km.value', 300)
            ->assertJsonPath('data.stats.efficiency_km_per_liter.value', 10);
        $this->getJson($this->api("{$url}&fuel_type=gasoline"))
            ->assertJsonPath('data.stats.fuel_liters.value', 20)
            ->assertJsonPath('data.stats.efficiency_km_per_liter.value', null);
        $this->getJson($this->api("{$url}&route_origin=Manila&route_destination=Batangas"))->assertJsonPath('data.stats.fuel_liters.value', 40);
        $this->getJson($this->api("{$url}&payment_method=fuel_card&driver_id={$ben->id}"))->assertJsonPath('data.stats.fuel_liters.value', 30);

        // The transaction list takes the same filters.
        $this->getJson($this->api('fuel-records?station='.urlencode('Shell, SLEX')))->assertJsonCount(2, 'data');
        $this->getJson($this->api('fuel-records?payment_method=cash'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('fuel-records?route_origin=Manila&route_destination=Batangas'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('fuel-records?sort=-odometer_km'))->assertJsonPath('data.0.odometer_km', 10_700);

        $this->getJson($this->api("{$url}&group_by=week&fuel_type=jet&route_origin=Manila"))
            ->assertStatus(422)->assertJsonValidationErrors(['group_by', 'fuel_type', 'route_destination']);
    }

    public function test_overview_validates_the_period(): void
    {
        $this->actingAsRole();
        $this->getJson($this->api('reports/overview?from=2026-10-31&to=2026-10-01&vehicle_id=999'))
            ->assertStatus(422)->assertJsonValidationErrors(['to', 'vehicle_id']);
        $this->getJson($this->api('reports/overview?from=2025-01-01&to=2026-10-01'))
            ->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->getJson($this->api('reports/overview'))->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_generates_lists_previews_and_deletes_reports(): void
    {
        $user = $this->actingAsRole(UserRole::Dispatcher);
        $truck = Vehicle::factory()->create(['plate_number' => 'ABC-1234']);
        $idle = Vehicle::factory()->create(['plate_number' => 'ZZZ-9999']);
        $driver = Driver::factory()->create();
        $this->fuel($truck, $driver, '2026-09-30 08:00', 1_000, 40);
        $this->fuel($truck, $driver, '2026-10-08 08:00', 1_250, 40);
        // Two days on the road (overnight trip) plus one more day.
        $this->trip($truck, $driver, '2026-10-02 20:00', TripStatus::Completed, ['actual_arrival_at' => '2026-10-03 06:00']);
        $this->trip($truck, $driver, '2026-10-09 08:00', TripStatus::Scheduled);
        $this->trip($truck, $driver, '2026-10-15 08:00', TripStatus::Cancelled);

        $this->getJson($this->api('reports'))->assertOk()->assertJsonCount(0, 'data');

        $report = $this->postJson($this->api('reports'), [
            'name' => 'October Fleet Utilization', 'type' => 'fleet', 'from' => '2026-10-01', 'to' => '2026-10-31',
        ])->assertCreated()
            ->assertJsonPath('data.type_label', 'Fleet')
            ->assertJsonPath('data.period_from', '2026-10-01')
            ->assertJsonPath('data.generated_by.name', $user->name)
            ->json('data');

        Report::factory()->create(['name' => 'Fuel spend', 'type' => 'fuel']);
        $this->getJson($this->api('reports'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->api('reports?type=fuel'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Fuel spend');
        $this->getJson($this->api('reports?search=october'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('reports?search='.urlencode($user->name)))->assertJsonCount(1, 'data');

        $this->getJson($this->api("reports/{$report['id']}"))->assertOk()
            ->assertJsonPath('data.name', 'October Fleet Utilization')
            ->assertJsonPath('data.preview.summary', ['total_vehicles' => 2, 'active_vehicles' => 1, 'total_distance_km' => 250, 'total_trips' => 3])
            ->assertJsonPath('data.preview.vehicles.0.plate_number', 'ABC-1234')
            ->assertJsonPath('data.preview.vehicles.0.trips', 2)
            ->assertJsonPath('data.preview.vehicles.0.active_days', 3)
            ->assertJsonPath('data.preview.vehicles.0.utilization_rate', 9.7)
            ->assertJsonPath('data.preview.vehicles.1.distance_km', null)
            ->assertJsonPath('data.preview.vehicles.1.utilization_rate', 0);

        // Filtered report: only that vehicle.
        $filtered = $this->postJson($this->api('reports'), [
            'name' => 'One truck', 'type' => 'trips', 'from' => '2026-10-01', 'to' => '2026-10-31', 'vehicle_id' => $truck->id,
        ])->assertCreated()->assertJsonPath('data.vehicle_id', $truck->id)->json('data');
        $this->getJson($this->api("reports/{$filtered['id']}"))->assertJsonCount(1, 'data.preview.vehicles');

        $this->postJson($this->api('reports'), ['type' => 'weekly', 'from' => '2026-10-05'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'type', 'to']);

        $this->deleteJson($this->api("reports/{$report['id']}"))->assertOk();
        $this->assertDatabaseMissing('reports', ['id' => $report['id']]);
    }

    public function test_viewers_can_only_delete_their_own_reports(): void
    {
        $viewer = $this->actingAsRole(UserRole::Viewer);
        $other = Report::factory()->create();
        $own = Report::factory()->create(['generated_by' => $viewer->id]);

        $this->deleteJson($this->api("reports/{$other->id}"))->assertForbidden();
        $this->deleteJson($this->api("reports/{$own->id}"))->assertOk();
    }
}
