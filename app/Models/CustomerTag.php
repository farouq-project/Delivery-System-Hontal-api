<?php

namespace App\Models;

use App\Models\Scopes\MerchantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerTag extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new MerchantScope());
    }

    protected $fillable = ['merchant_id', 'name', 'color', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(
            Customer::class,
            'customer_tag_assignments',
            'tag_id',
            'customer_id'
        )->withPivot('assigned_by', 'created_at');
    }
}
