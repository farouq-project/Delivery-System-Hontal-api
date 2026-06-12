<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VipConfig extends Model
{
    protected $fillable = ['merchant_id', 'vip_level', 'score_value'];

    protected $casts = ['score_value' => 'integer'];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
