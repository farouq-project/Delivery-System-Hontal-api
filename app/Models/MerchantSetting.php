<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantSetting extends Model
{
    protected $fillable = [
        'merchant_id', 'depot_address', 'depot_latitude', 'depot_longitude',
        'routing_algorithm', 'auto_geocode_enabled', 'max_stops_per_driver', 'klotter_size',
        'order_edit_pin', 'hide_driver_logout',
        'working_hours_start', 'working_hours_end', 'gps_ping_interval_sec', 'gps_history_days',
        // Phase 3 — Merchant Platform
        'working_days', 'holiday_mode_enabled', 'max_delivery_radius_km', 'auto_dispatch',
        'tracking_expiry_hours', 'public_tracking_enabled', 'show_estimated_arrival', 'driver_location_visible',
        'whatsapp_notifications_enabled', 'email_notifications_enabled', 'push_notifications_enabled',
        'invoice_prefix', 'invoice_date_format',
    ];

    protected $casts = [
        'auto_geocode_enabled'             => 'boolean',
        'hide_driver_logout'               => 'boolean',
        'depot_latitude'                   => 'float',
        'depot_longitude'                  => 'float',
        'max_stops_per_driver'             => 'integer',
        'klotter_size'                     => 'integer',
        'gps_ping_interval_sec'            => 'integer',
        'gps_history_days'                 => 'integer',
        // Phase 3
        'working_days'                     => 'array',
        'holiday_mode_enabled'             => 'boolean',
        'max_delivery_radius_km'           => 'integer',
        'auto_dispatch'                    => 'boolean',
        'tracking_expiry_hours'            => 'integer',
        'public_tracking_enabled'          => 'boolean',
        'show_estimated_arrival'           => 'boolean',
        'driver_location_visible'          => 'boolean',
        'whatsapp_notifications_enabled'   => 'boolean',
        'email_notifications_enabled'      => 'boolean',
        'push_notifications_enabled'       => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
