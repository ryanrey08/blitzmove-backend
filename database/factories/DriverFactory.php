<?php

namespace Database\Factories;

use App\Enums\DriverStatus;
use App\Enums\LicenseType;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Driver> */
class DriverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_code' => 'DRV-'.fake()->unique()->numerify('####'),
            'full_name' => fake()->name(),
            'phone' => fake()->numerify('09## ### ####'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'license_number' => fake()->unique()->bothify('N##-##-######'),
            'license_type' => LicenseType::Professional,
            'license_expiry' => now()->addYears(2)->toDateString(),
            'date_hired' => now()->subYears(2)->toDateString(),
            'status' => DriverStatus::Available,
        ];
    }
}
