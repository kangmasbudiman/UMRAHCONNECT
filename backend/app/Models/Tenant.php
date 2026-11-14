<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'license_expires_on' => 'date',
            'verified_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /** Hanya PPIU terverifikasi yang tampil di marketplace (BR-01). */
    public function scopeVerified(Builder $q): Builder
    {
        return $q->where('verification_status', 'verified');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(TenantMember::class)->withoutTenantScope();
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class)->withoutTenantScope();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenantDocument::class)->withoutTenantScope();
    }

    /** Field izin terkunci setelah PPIU pernah terverifikasi (diubah lewat Platform, BP-11). */
    public function licenseLocked(): bool
    {
        return in_array($this->verification_status, ['verified', 'suspended'], true);
    }

    public function canBeEditedByOwner(): bool
    {
        return in_array($this->verification_status, ['draft', 'needs_revision', 'verified'], true);
    }
}
