<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PooledRoute extends Model
{
    protected $fillable = [
        'batch_id', 'driver_id', 'status',
        'total_stops', 'completed_stops', 'created_by',
        'assigned_at', 'started_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'total_stops'     => 'integer',
        'completed_stops' => 'integer',
        'assigned_at'     => 'datetime',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function batch()
    {
        return $this->belongsTo(HontalKirimBatch::class, 'batch_id');
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stops()
    {
        return $this->hasMany(PooledStop::class)->orderBy('stop_sequence');
    }
}
