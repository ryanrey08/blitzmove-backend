<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plate_number' => fake()->unique()->bothify('???-####'),
            'vehicle_type' => VehicleType::ClosedVan,
            'make' => 'Isuzu',
            'model' => 'NPR',
            'year' => 2023,
            'capacity_kg' => 4000,
            'fuel_type' => FuelType::Diesel,
            'status' => VehicleStatus::Available,
        ];
    }
}
