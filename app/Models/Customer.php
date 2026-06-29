<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ulid', 'merchant_id', 'customer_name', 'phone', 'email',
        'default_address', 'default_latitude', 'default_longitude',
        'vip_level', 'cluster', 'notes', 'total_orders', 'last_order_at', 'is_active',
    ];

    protected $casts = [
        'default_latitude' => 'float',
        'default_longitude' => 'float',
        'total_orders' => 'integer',
        'last_order_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orders()
    {
        return $this->hasMany(DeliveryOrder::class)->orderByDesc('created_at');
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('customer_name', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
