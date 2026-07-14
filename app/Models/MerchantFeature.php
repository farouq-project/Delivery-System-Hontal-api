<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantFeature extends Model
{
    protected $fillable = ['merchant_id', 'feature', 'is_enabled', 'config'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config'     => 'array',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
