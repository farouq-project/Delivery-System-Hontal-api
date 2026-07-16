<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price',
        'delivery_limit', 'branch_limit', 'driver_limit',
        'features', 'is_active', 'display_order',
    ];

    protected $casts = [
        'features'       => 'array',
        'is_active'      => 'boolean',
        'monthly_price'  => 'integer',
        'delivery_limit' => 'integer',
        'branch_limit'   => 'integer',
        'driver_limit'   => 'integer',
        'display_order'  => 'integer',
    ];

    public function subscriptions()
    {
        return $this->hasMany(MerchantSubscription::class, 'plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('display_order');
    }
}
