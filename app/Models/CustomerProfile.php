<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'customer_id', 'merchant_id',
        'first_order_at', 'last_order_at',
        'total_orders', 'total_deliveries', 'total_failed',
        'total_spending', 'avg_order_value', 'avg_delivery_time_hours',
        'preferred_payment', 'preferred_delivery_time',
        'health_status', 'segment',
        'last_health_check_at', 'last_segment_check_at',
    ];

    protected $casts = [
        'first_order_at'         => 'datetime',
        'last_order_at'          => 'datetime',
        'last_health_check_at'   => 'datetime',
        'last_segment_check_at'  => 'datetime',
        'total_orders'           => 'integer',
        'total_deliveries'       => 'integer',
        'total_failed'           => 'integer',
        'total_spending'         => 'float',
        'avg_order_value'        => 'float',
        'avg_delivery_time_hours'=> 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
