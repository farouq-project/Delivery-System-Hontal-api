<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HontalKirimCredit extends Model
{
    protected $fillable = [
        'merchant_id', 'balance_idr',
        'low_balance_threshold_idr', 'low_balance_notified_at',
    ];

    protected $casts = [
        'balance_idr'               => 'integer',
        'low_balance_threshold_idr' => 'integer',
        'low_balance_notified_at'   => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions()
    {
        return $this->hasMany(HontalKirimCreditTransaction::class, 'merchant_id', 'merchant_id');
    }

    public function isBlocked(): bool
    {
        return $this->balance_idr <= 0;
    }

    public function isLow(): bool
    {
        return $this->balance_idr > 0 && $this->balance_idr < $this->low_balance_threshold_idr;
    }
}
