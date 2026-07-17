<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ulid'              => (string) Str::ulid(),
            'customer_name'     => fake()->name(),
            'phone'             => fake()->phoneNumber(),
            'default_address'   => fake()->address(),
            'default_latitude'  => -6.9 + fake()->randomFloat(4, 0, 0.5),
            'default_longitude' => 107.6 + fake()->randomFloat(4, 0, 0.5),
            'location_source'   => 'unknown',
            'vip_level'         => 'standard',
            'is_active'         => true,
        ];
    }
}
