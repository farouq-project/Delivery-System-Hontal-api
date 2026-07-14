<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTimeline extends Model
{
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'customer_id', 'merchant_id', 'event_type', 'event_data',
        'actor_id', 'actor_role', 'occurred_at',
    ];

    protected $casts = [
        'event_data'  => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
