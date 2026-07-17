<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    // Valid location_source values
    const LOCATION_SOURCES = ['google_maps_link', 'manual_pin', 'address_geocoding', 'unknown'];

    protected $fillable = [
        'ulid', 'merchant_id', 'customer_name', 'phone', 'email',
        'default_address', 'default_latitude', 'default_longitude',
        'location_source', 'location_last_verified_at',
        'vip_level', 'cluster', 'notes', 'total_orders', 'last_order_at', 'is_active',
    ];

    protected $casts = [
        'default_latitude'           => 'float',
        'default_longitude'          => 'float',
        'total_orders'               => 'integer',
        'last_order_at'              => 'datetime',
        'location_last_verified_at'  => 'datetime',
        'is_active'                  => 'boolean',
    ];

    public function locationConfidence(): string
    {
        return match ($this->location_source) {
            'google_maps_link'  => 'high',
            'manual_pin'        => 'medium',
            'address_geocoding' => 'medium',
            default             => 'low',
        };
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orders()
    {
        return $this->hasMany(DeliveryOrder::class)->orderByDesc('created_at');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(CustomerTimeline::class)->orderByDesc('occurred_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_tag_assignments', 'customer_id', 'tag_id')
            ->withPivot('assigned_by', 'created_at');
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
