<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = ['awaiting_dp', 'dp_verified', 'fully_paid'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'commission_snapshot' => 'array',
            'hold_expires_at' => 'datetime', 'confirmed_at' => 'datetime', 'dp_verified_at' => 'datetime',
            'fully_paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'cancel_requested_at' => 'datetime',
            'total_amount' => 'integer', 'dp_amount' => 'integer', 'paid_amount' => 'integer', 'participant_count' => 'integer',
        ];
    }

    public function departure(): BelongsTo { return $this->belongsTo(Departure::class)->withoutTenantScope(); }

    public function package(): BelongsTo { return $this->belongsTo(Package::class)->withoutTenantScope(); }

    public function booker(): BelongsTo { return $this->belongsTo(User::class, 'booker_user_id'); }

    public function agent(): BelongsTo { return $this->belongsTo(Agent::class); }

    public function participants(): HasMany { return $this->hasMany(BookingParticipant::class)->withoutTenantScope(); }

    public function invoices(): HasMany { return $this->hasMany(Invoice::class)->withoutTenantScope()->orderBy('created_at')->orderBy('id'); }

    public function refunds(): HasMany { return $this->hasMany(Refund::class)->withoutTenantScope()->orderBy('created_at')->orderBy('id'); }

    public function notes(): HasMany { return $this->hasMany(BookingNote::class)->withoutTenantScope()->orderByDesc('created_at')->orderByDesc('id'); }

    public function payments()
    {
        return Payment::withoutTenantScope()->whereIn('invoice_id', Invoice::withoutTenantScope()->where('booking_id', $this->id)->select('id'));
    }
}
