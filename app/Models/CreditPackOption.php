<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPackOption extends Model
{
    protected $fillable = ['name', 'slug', 'credits', 'price_idr', 'is_active', 'display_order'];

    protected $casts = [
        'credits'       => 'integer',
        'price_idr'     => 'integer',
        'is_active'     => 'boolean',
        'display_order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('display_order');
    }
}
