<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantCluster extends Model
{
    protected $fillable = ['merchant_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public static function namesForMerchant(int $merchantId): array
    {
        return static::where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
