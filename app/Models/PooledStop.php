<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PooledStop extends Model
{
    protected $fillable = [
        'pooled_route_id', 'stop_sequence', 'stop_type',
        'depot_id', 'order_id',
        'latitude', 'longitude',
        'contact_name', 'contact_phone',
        'status', 'estimated_arrival', 'actual_arrival',
        'distance_from_prev_m', 'is_mid_route_addition', 'notes',
    ];

    protected $casts = [
        'latitude'              => 'float',
        'longitude'             => 'float',
        'stop_sequence'         => 'integer',
        'distance_from_prev_m'  => 'integer',
        'is_mid_route_addition' => 'boolean',
        'estimated_arrival'     => 'datetime',
        'actual_arrival'        => 'datetime',
    ];

    public function route()
    {
        return $this->belongsTo(PooledRoute::class, 'pooled_route_id');
    }

    public function depot()
    {
        return $this->belongsTo(Depot::class);
    }

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }

    // For pickup stops: the orders collected at this depot stop
    public function pickupOrders()
    {
        return $this->belongsToMany(DeliveryOrder::class, 'pooled_stop_orders', 'pooled_stop_id', 'order_id');
    }

    public function isPickup(): bool  { return $this->stop_type === 'pickup'; }
    public function isDropoff(): bool { return $this->stop_type === 'dropoff'; }
    public function isPending(): bool { return $this->status === 'pending'; }
}
