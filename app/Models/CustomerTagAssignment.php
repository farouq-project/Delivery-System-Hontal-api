<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTagAssignment extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['customer_id', 'tag_id', 'assigned_by'];

    protected $casts = ['created_at' => 'datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(CustomerTag::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
