<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantBilling extends Model
{
    protected $table = 'merchant_billing';

    protected $fillable = [
        'merchant_id',
        'subscription_id',
        'invoice_number',
        'invoice_amount',
        'due_date',
        'payment_status',
        'last_payment_at',
        'outstanding_balance',
        'renewal_date',
        'billing_notes',
    ];

    protected $casts = [
        'due_date'        => 'date',
        'renewal_date'    => 'date',
        'last_payment_at' => 'datetime',
        'invoice_amount'  => 'integer',
        'outstanding_balance' => 'integer',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MerchantSubscription::class);
    }
}
