<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'ulid', 'merchant_id', 'name', 'email', 'phone', 'password',
        'role', 'avatar_path', 'is_active', 'can_logout', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active'  => 'boolean',
            'can_logout' => 'boolean',
        ];
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function driver()
    {
        return $this->hasOne(Driver::class);
    }

    public function isSuperAdmin(): bool { return $this->role === 'super_admin'; }
    public function isDeveloper(): bool  { return $this->role === 'developer'; }
    public function isOwner(): bool      { return $this->role === 'merchant_owner'; }
    public function isDispatcher(): bool { return $this->role === 'dispatcher'; }
    public function isDriver(): bool     { return $this->role === 'driver'; }
}
