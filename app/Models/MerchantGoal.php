<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Model;

class MerchantGoal extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'merchant_id', 'metric', 'target_value',
        'period_type', 'period_start', 'period_end', 'notes',
    ];

    protected $casts = [
        'target_value' => 'float',
        'period_start' => 'date',
        'period_end'   => 'date',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function isActive(): bool
    {
        $today = now()->toDateString();
        return $this->period_start->toDateString() <= $today
            && $this->period_end->toDateString() >= $today;
    }
}
