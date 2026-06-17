<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantSetting extends Model
{
    protected $fillable = [
        'merchant_id', 'depot_address', 'depot_latitude', 'depot_longitude',
        'routing_algorithm', 'auto_geocode_enabled', 'max_stops_per_driver', 'klotter_size',
        'order_edit_pin', 'hide_driver_logout',
        'working_hours_start', 'working_hours_end', 'gps_ping_interval_sec',
        'gps_history_days',
    ];

    protected $casts = [
        'auto_geocode_enabled'  => 'boolean',
        'hide_driver_logout'   => 'boolean',
        'depot_latitude' => 'float',
        'depot_longitude' => 'float',
        'max_stops_per_driver' => 'integer',
        'klotter_size' => 'integer',
        'gps_ping_interval_sec' => 'integer',
        'gps_history_days' => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
