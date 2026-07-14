<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSegment extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'merchant_id', 'name', 'segment_key', 'rules', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'rules'      => 'array',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
