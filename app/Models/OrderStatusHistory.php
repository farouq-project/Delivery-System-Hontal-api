<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'from_status', 'to_status', 'changed_by', 'changed_by_role',
        'notes', 'latitude', 'longitude', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
