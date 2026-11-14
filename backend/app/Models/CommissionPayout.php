<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionPayout extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'requested_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }

    public function commissions(): HasMany { return $this->hasMany(Commission::class, 'payout_id')->withoutTenantScope(); }
}
