<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoogleApiUsageLog extends Model
{
    protected $table = 'google_api_usage_logs';

    protected $fillable = [
        'merchant_id',
        'api_type',
        'request_count',
        'estimated_units',
        'cache_hit',
        'cache_key',
        'response_time_ms',
    ];

    protected $casts = [
        'request_count'    => 'integer',
        'estimated_units'  => 'integer',
        'cache_hit'        => 'boolean',
        'response_time_ms' => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
