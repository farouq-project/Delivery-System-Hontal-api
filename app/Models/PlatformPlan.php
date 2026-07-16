<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformPlan extends Model
{
    use HasFactory, SoftDeletes;

    public const AVAILABLE_FEATURES = [
        'executive_dashboard'   => 'Executive Dashboard',
        'business_intelligence' => 'Business Intelligence',
        'customer_domain'       => 'Customer Intelligence',
        'merchant_platform'     => 'Merchant Platform',
        'tracking'              => 'Tracking',
        'marketing'             => 'Marketing',
        'api_access'            => 'API Access',
        'priority_support'      => 'Priority Support',
    ];

    protected $fillable = [
        'name', 'slug', 'description', 'monthly_price',
        'delivery_limit', 'branch_limit', 'driver_limit', 'customer_limit',
        'trial_days', 'features', 'is_active', 'display_order',
    ];

    protected $casts = [
        'features'       => 'array',
        'is_active'      => 'boolean',
        'monthly_price'  => 'integer',
        'delivery_limit' => 'integer',
        'branch_limit'   => 'integer',
        'driver_limit'   => 'integer',
        'customer_limit' => 'integer',
        'trial_days'     => 'integer',
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
