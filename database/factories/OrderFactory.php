<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        $pickup = now()->addDay()->setTime(8, 0);

        return [
            'order_number' => 'ORD-'.now()->year.'-'.fake()->unique()->numerify('9###'),
            'customer_name' => fake()->company(),
            'customer_contact' => fake()->numerify('09## ### ####'),
            'pickup_address' => fake()->address(),
            'delivery_address' => fake()->address(),
            'scheduled_pickup_at' => $pickup,
            'scheduled_delivery_at' => $pickup->copy()->addHours(6),
            'cargo_description' => 'Palletized goods',
            'quantity' => 10,
            'weight_kg' => 1500,
            'status' => OrderStatus::Pending,
        ];
    }
}
