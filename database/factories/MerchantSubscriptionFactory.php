<?php

namespace Database\Factories;

use App\Models\MerchantSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantSubscription>
 */
class MerchantSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'status'     => 'active',
            'started_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ];
    }
}
