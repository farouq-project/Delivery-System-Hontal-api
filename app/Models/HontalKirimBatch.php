<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HontalKirimBatch extends Model
{
    protected $fillable = [
        'window_start', 'window_end', 'status', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end'   => 'datetime',
        'closed_at'    => 'datetime',
    ];

    public function orders()
    {
        return $this->hasMany(DeliveryOrder::class, 'batch_id');
    }

    public function pooledRoutes()
    {
        return $this->hasMany(PooledRoute::class, 'batch_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool     { return $this->status === 'open'; }
    public function isClosed(): bool   { return $this->status === 'closed'; }
    public function isDispatched(): bool { return $this->status === 'dispatched'; }
}
