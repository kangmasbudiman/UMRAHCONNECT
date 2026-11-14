<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /** Kolom kata sandi di Database Design v1.2 bernama password_hash. */
    protected $authPasswordName = 'password_hash';

    protected $fillable = ['name', 'email', 'phone', 'password_hash', 'status', 'platform_role', 'email_verified_at', 'phone_verified_at'];

    protected $hidden = ['password_hash', 'remember_token', 'two_factor_secret_enc', 'two_factor_recovery_codes_enc'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_hash' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret_enc' => 'encrypted',
            'two_factor_recovery_codes_enc' => 'encrypted',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMember::class);
    }

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }
}
