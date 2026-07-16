<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MerchantSubscription extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = ['trial', 'active', 'paused', 'suspended', 'expired', 'cancelled'];

    protected $fillable = [
        'merchant_id', 'plan_id', 'status',
        'started_at', 'expires_at', 'trial_ends_at',
        'paused_at', 'resumed_at',
        'billing_cycle', 'next_invoice_date',
    ];

    protected $casts = [
        'started_at'        => 'datetime',
        'expires_at'        => 'datetime',
        'trial_ends_at'     => 'datetime',
        'paused_at'         => 'datetime',
        'resumed_at'        => 'datetime',
        'next_invoice_date' => 'date',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function plan()
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id');
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trial' && $this->trial_ends_at?->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function daysRemainingInTrial(): ?int
    {
        if ($this->status !== 'trial' || !$this->trial_ends_at) {
            return null;
        }
        return max(0, (int) now()->diffInDays($this->trial_ends_at, false));
    }
}
