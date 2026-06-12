<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteAssignment extends Model
{
    protected $fillable = [
        'route_id', 'driver_id', 'sequence_number',
        'estimated_start_at', 'estimated_end_at', 'actual_start_at', 'actual_end_at',
        'total_stops', 'completed_stops', 'failed_stops', 'total_distance_m', 'status',
    ];

    protected $casts = [
        'estimated_start_at' => 'datetime',
        'estimated_end_at' => 'datetime',
        'actual_start_at' => 'datetime',
        'actual_end_at' => 'datetime',
        'total_stops' => 'integer',
        'completed_stops' => 'integer',
        'failed_stops' => 'integer',
        'total_distance_m' => 'integer',
    ];

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function stops()
    {
        return $this->hasMany(RouteStop::class)->orderBy('stop_sequence');
    }
}
