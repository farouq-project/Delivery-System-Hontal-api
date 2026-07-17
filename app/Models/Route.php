<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Route extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = [
        'ulid', 'merchant_id', 'route_date', 'label', 'status',
        'total_stops', 'total_drivers', 'total_distance_m', 'estimated_duration_min',
        'generation_method', 'generated_by', 'generated_at', 'locked_at', 'locked_by', 'notes',
        // V2 analytics
        'routing_mode', 'distance_before_optimization_m', 'optimization_saving_m',
        'google_calls', 'cache_hits', 'quality_score', 'batch_count',
    ];

    protected $casts = [
        'route_date'                     => 'date',
        'generated_at'                   => 'datetime',
        'locked_at'                      => 'datetime',
        'total_stops'                    => 'integer',
        'total_drivers'                  => 'integer',
        'total_distance_m'               => 'integer',
        'estimated_duration_min'         => 'integer',
        'distance_before_optimization_m' => 'integer',
        'optimization_saving_m'          => 'integer',
        'google_calls'                   => 'integer',
        'cache_hits'                     => 'integer',
        'quality_score'                  => 'float',
        'batch_count'                    => 'integer',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function assignments()
    {
        return $this->hasMany(RouteAssignment::class)->with(['driver', 'stops.order']);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
