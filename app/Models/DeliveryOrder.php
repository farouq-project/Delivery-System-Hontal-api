<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'ulid', 'order_number', 'merchant_id', 'driver_id', 'customer_id',
        'customer_name', 'customer_phone',
        'product_name', 'product_notes', 'items', 'order_value',
        'delivery_address', 'delivery_latitude', 'delivery_longitude', 'delivery_notes',
        'requested_delivery_date', 'requested_delivery_start', 'requested_delivery_end',
        'status', 'failure_reason', 'cancellation_reason',
        'order_created_at', 'assigned_at', 'picked_up_at', 'delivered_at', 'failed_at',
        'route_sequence', 'estimated_distance_m', 'estimated_duration_min', 'actual_distance_m',
        'import_batch_id', 'external_order_id', 'created_by', 'updated_by',
        'cashier_name', 'payment_method',
    ];

    protected $casts = [
        'items' => 'array',
        'order_value' => 'float',
        'delivery_latitude' => 'float',
        'delivery_longitude' => 'float',
        'requested_delivery_date' => 'date',
        'order_created_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'estimated_distance_m' => 'integer',
        'estimated_duration_min' => 'integer',
        'actual_distance_m' => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('created_at');
    }

    public function proof()
    {
        return $this->hasOne(ProofOfDelivery::class, 'order_id');
    }

    public function routeStop()
    {
        return $this->hasOne(RouteStop::class, 'order_id');
    }

    public function scopeForMerchant($query, int $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('requested_delivery_date', $date);
    }

    public function isPending(): bool    { return $this->status === 'pending'; }
    public function isAssigned(): bool   { return $this->status === 'assigned'; }
    public function isDelivered(): bool  { return $this->status === 'delivered'; }
    public function hasFailed(): bool    { return $this->status === 'failed'; }
}
