<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantActivityLog extends Model
{
    protected $table = 'merchant_activity_log';

    protected $fillable = [
        'merchant_id',
        'event_type',
        'description',
        'context',
        'actor_id',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
