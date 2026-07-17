<?php

namespace Database\Factories;

use App\Models\DeliveryOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeliveryOrder>
 */
class DeliveryOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ulid'             => (string) Str::ulid(),
            'order_number'     => 'ORD-' . strtoupper(Str::random(6)),
            'customer_name'    => fake()->name(),
            'customer_phone'   => fake()->phoneNumber(),
            'delivery_address' => fake()->address(),
            'delivery_latitude'=> -6.9 + fake()->randomFloat(4, 0, 0.5),
            'delivery_longitude'=> 107.6 + fake()->randomFloat(4, 0, 0.5),
            'status'           => 'pending',
            'order_value'      => fake()->randomFloat(2, 50000, 500000),
            'order_created_at' => now(),
        ];
    }
}
