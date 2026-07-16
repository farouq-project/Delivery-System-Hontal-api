<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'ulid', 'company_name', 'slug', 'address', 'phone', 'email', 'timezone', 'logo_path',
        // Phase 3 — Merchant Platform
        'tax_number', 'invoice_footer', 'brand_color',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function drivers()
    {
        return $this->hasMany(Driver::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function orders()
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function routes()
    {
        return $this->hasMany(Route::class);
    }

    public function settings()
    {
        return $this->hasOne(MerchantSetting::class);
    }

    public function cashiers()
    {
        return $this->hasMany(MerchantCashier::class);
    }

    public function clusters()
    {
        return $this->hasMany(MerchantCluster::class);
    }

    public function features()
    {
        return $this->hasMany(MerchantFeature::class);
    }

    public function vipConfigs()
    {
        return $this->hasMany(VipConfig::class);
    }

    public function subscription()
    {
        return $this->hasOne(MerchantSubscription::class)->latestOfMany();
    }

    public function branches()
    {
        return $this->hasMany(MerchantBranch::class);
    }

    public function activityLog()
    {
        return $this->hasMany(MerchantActivityLog::class)->orderByDesc('created_at');
    }

    public function billing()
    {
        return $this->hasOne(MerchantBilling::class);
    }

    public function getVipScore(string $level): int
    {
        static $defaults = ['standard' => 0, 'silver' => 50, 'gold' => 100, 'platinum' => 200];
        $config = $this->vipConfigs->where('vip_level', $level)->first();
        return $config ? $config->score_value : ($defaults[$level] ?? 0);
    }
}
