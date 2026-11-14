<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rencana tabungan umrah milik pengguna. Hanya pencatat: platform tidak menyimpan uang. */
class SavingsPlan extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_date' => 'date'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SavingsEntry::class, 'plan_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sisa bulan sampai target (minimal 1) dan setoran per bulan yang dibutuhkan. */
    public function recalc(): void
    {
        $months = max(1, (int) floor(now('Asia/Jakarta')->startOfMonth()->diffInMonths($this->target_date->copy()->startOfMonth(), false)));
        $remaining = max(0, $this->target_amount - $this->saved_amount);
        $this->monthly_amount = (int) (ceil($remaining / $months / 1000) * 1000);
        if ($remaining === 0 && $this->status === 'active') {
            $this->status = 'reached';
        } elseif ($remaining > 0 && $this->status === 'reached') {
            $this->status = 'active';
        }
    }
}
