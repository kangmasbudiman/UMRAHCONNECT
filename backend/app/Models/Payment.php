<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'verified_at' => 'datetime', 'claimed_amount' => 'integer', 'verified_amount' => 'integer'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class)->withoutTenantScope(); }

    public function receipt(): HasOne { return $this->hasOne(Receipt::class)->withoutTenantScope(); }

    public function proof(): BelongsTo { return $this->belongsTo(File::class, 'proof_file_id'); }
}
