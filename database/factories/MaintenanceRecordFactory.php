<?php

namespace Database\Factories;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceRecord> */
class MaintenanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_number' => 'MNT-F-'.fake()->unique()->numerify('#####'),
            'vehicle_id' => Vehicle::factory(),
            'service_type' => MaintenanceType::OilChange,
            'category' => MaintenanceCategory::Preventive,
            'status' => MaintenanceStatus::Scheduled,
            'scheduled_date' => today()->addDays(fake()->numberBetween(10, 40)),
            'estimated_cost' => fake()->randomFloat(2, 1500, 15000),
        ];
    }

    public function completed(?string $on = null): static
    {
        return $this->state(fn () => [
            'status' => MaintenanceStatus::Completed,
            'scheduled_date' => $on ?? today()->subDays(3)->toDateString(),
            'service_date' => $on ?? today()->subDays(3)->toDateString(),
            'completed_at' => now(),
        ]);
    }
}
