<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantPaymentMethod extends Model
{
    protected $fillable = [
        'merchant_id', 'method_key', 'label', 'is_enabled', 'is_default', 'sort_order',
    ];

    protected $casts = [
        'is_enabled'  => 'boolean',
        'is_default'  => 'boolean',
        'sort_order'  => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public static function keysForMerchant(int $merchantId): array
    {
        return static::where('merchant_id', $merchantId)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->pluck('method_key')
            ->all();
    }
}
