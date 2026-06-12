<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteStop extends Model
{
    protected $fillable = [
        'route_id', 'route_assignment_id', 'order_id', 'stop_sequence',
        'distance_score', 'waiting_score', 'window_score', 'vip_score', 'total_score',
        'estimated_arrival', 'actual_arrival',
        'distance_from_prev_m', 'duration_from_prev_min',
        'is_manually_placed', 'is_locked',
    ];

    protected $casts = [
        'estimated_arrival' => 'datetime',
        'actual_arrival' => 'datetime',
        'distance_score' => 'float',
        'waiting_score' => 'float',
        'window_score' => 'float',
        'vip_score' => 'float',
        'total_score' => 'float',
        'distance_from_prev_m' => 'integer',
        'duration_from_prev_min' => 'integer',
        'is_manually_placed' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function route()
    {
        return $this->belongsTo(Route::class);
    }

    public function assignment()
    {
        return $this->belongsTo(RouteAssignment::class, 'route_assignment_id');
    }

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }
}
