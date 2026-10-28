<?php

namespace Database\Factories;

use App\Enums\FuelType;
use App\Models\FuelRecord;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FuelRecord> */
class FuelRecordFactory extends Factory
{
    public function definition(): array
    {
        $liters = fake()->randomFloat(2, 30, 120);
        $price = fake()->randomFloat(2, 55, 70);

        return [
            'vehicle_id' => Vehicle::factory(),
            'fueled_at' => now()->subDays(fake()->numberBetween(0, 30)),
            'fuel_type' => FuelType::Diesel,
            'liters' => $liters,
            'price_per_liter' => $price,
            'total_cost' => round($liters * $price, 2),
        ];
    }
}
