<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HontalKirimCreditTransaction extends Model
{
    // Immutable ledger — no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'merchant_id', 'type', 'amount_idr', 'fee_amount_idr',
        'order_id', 'note', 'created_by',
    ];

    protected $casts = [
        'amount_idr'     => 'integer',
        'fee_amount_idr' => 'integer',
        'created_at'     => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function order()
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
