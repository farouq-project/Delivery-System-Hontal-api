<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ulid', 'merchant_id', 'user_id', 'driver_name', 'phone',
        'vehicle_type', 'vehicle_plate', 'vehicle_capacity_kg',
        'status', 'current_lat', 'current_lng', 'last_seen', 'is_active', 'notes',
    ];

    protected $casts = [
        'current_lat' => 'float',
        'current_lng' => 'float',
        'last_seen' => 'datetime',
        'is_active' => 'boolean',
        'vehicle_capacity_kg' => 'float',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function routeAssignments()
    {
        return $this->hasMany(RouteAssignment::class);
    }

    public function locations()
    {
        return $this->hasMany(DriverLocation::class)->orderByDesc('recorded_at');
    }

    public function latestLocation()
    {
        return $this->hasOne(DriverLocation::class)->latestOfMany('recorded_at');
    }

    public function todayAssignment()
    {
        return $this->hasOne(RouteAssignment::class)
            ->whereHas('route', fn($q) => $q->where('route_date', today()))
            ->with(['stops.order']);
    }
}
