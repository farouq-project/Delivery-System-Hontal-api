<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverLocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'driver_id', 'merchant_id', 'route_assignment_id',
        'latitude', 'longitude', 'accuracy_m', 'speed_kmh', 'bearing_deg',
        'battery_pct', 'recorded_at', 'created_at',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_m' => 'float',
        'speed_kmh' => 'float',
        'bearing_deg' => 'float',
        'battery_pct' => 'integer',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
