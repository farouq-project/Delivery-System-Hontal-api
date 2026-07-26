<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantCreditPurchase extends Model
{
    protected $fillable = [
        'merchant_id', 'subscription_id', 'credit_pack_option_id',
        'credits_granted', 'price_paid_idr', 'granted_by', 'note',
    ];

    protected $casts = [
        'credits_granted' => 'integer',
        'price_paid_idr'  => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(MerchantSubscription::class, 'subscription_id');
    }

    public function packOption()
    {
        return $this->belongsTo(CreditPackOption::class, 'credit_pack_option_id');
    }

    public function grantedByUser()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
