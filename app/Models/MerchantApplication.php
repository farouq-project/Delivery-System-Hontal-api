<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantApplication extends Model
{
    use HasFactory, SoftDeletes;

    // Valid status values
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'converted'];

    protected $fillable = [
        'company_name', 'owner_name', 'email', 'phone',
        'city', 'business_type', 'branch_count',
        'estimated_monthly_deliveries', 'selected_plan',
        'notes', 'status', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at'                  => 'datetime',
        'branch_count'                 => 'integer',
        'estimated_monthly_deliveries' => 'integer',
    ];

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
