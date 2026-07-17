<?php

namespace Database\Factories;

use App\Models\MerchantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantSetting>
 */
class MerchantSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'depot_address'                  => fake()->address(),
            'depot_latitude'                 => -6.9 + fake()->randomFloat(4, 0, 0.5),
            'depot_longitude'                => 107.6 + fake()->randomFloat(4, 0, 0.5),
            'routing_algorithm'              => 'balanced',
            'max_stops_per_driver'           => 20,
            'klotter_size'                   => 10,
            'location_validation_radius'     => 30,
            'location_change_warning_radius' => 2,
            'tracking_expiry_hours'          => 48,
            'public_tracking_enabled'        => true,
            'show_estimated_arrival'         => true,
            'driver_location_visible'        => true,
            'working_hours_start'            => '08:00',
            'working_hours_end'              => '17:00',
        ];
    }
}
