<?php

namespace Database\Factories;

use App\Enums\TripStatus;
use App\Models\Driver;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Trip> */
class TripFactory extends Factory
{
    public function definition(): array
    {
        $departure = now()->addDay()->setTime(8, 0);

        return [
            'trip_number' => 'TRIP-'.now()->year.'-'.fake()->unique()->numerify('9###'),
            'vehicle_id' => Vehicle::factory(),
            'driver_id' => Driver::factory(),
            'origin' => 'Manila',
            'destination' => 'Batangas City',
            'departure_at' => $departure,
            'estimated_arrival_at' => $departure->copy()->addHours(4),
            'status' => TripStatus::Scheduled,
        ];
    }
}
