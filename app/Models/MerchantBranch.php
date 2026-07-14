<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantBranch extends Model
{
    protected $fillable = [
        'merchant_id', 'name', 'address', 'depot_latitude', 'depot_longitude',
        'working_hours_start', 'working_hours_end', 'working_days',
        'max_stops_per_driver', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'depot_latitude'       => 'float',
        'depot_longitude'      => 'float',
        'working_days'         => 'array',
        'max_stops_per_driver' => 'integer',
        'is_active'            => 'boolean',
        'sort_order'           => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
