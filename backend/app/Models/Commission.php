<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'earned_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class)->withoutTenantScope(); }

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }
}
