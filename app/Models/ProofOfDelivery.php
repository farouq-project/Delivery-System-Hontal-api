<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProofOfDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'driver_id', 'photo_path', 'photo_thumbnail',
        'captured_latitude', 'captured_longitude', 'recipient_name', 'notes',
        'captured_at', 'created_at',
    ];

    protected $casts = [
        'captured_latitude' => 'float',
        'captured_longitude' => 'float',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }
}
