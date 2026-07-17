<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();
        return [
            'ulid'         => (string) Str::ulid(),
            'company_name' => $name,
            'slug'         => Str::slug($name) . '-' . fake()->unique()->numberBetween(1000, 9999),
            'address'      => fake()->address(),
            'phone'        => fake()->phoneNumber(),
            'email'        => fake()->unique()->companyEmail(),
            'timezone'     => 'Asia/Jakarta',
        ];
    }
}
