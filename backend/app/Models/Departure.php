<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Departure extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'final_payment_due_on' => 'date',
            'quota' => 'integer',
            'seats_held' => 'integer',
            'seats_confirmed' => 'integer',
            'dp_min_per_pax_amount' => 'integer',
            'dp_deadline_hours' => 'integer',
        ];
    }

    /** CR-03: sisa = kuota − ditahan − terkonfirmasi. */
    public function seatsRemaining(): int
    {
        return max(0, $this->quota - $this->seats_held - $this->seats_confirmed);
    }

    /** Keberangkatan yang ditampilkan ke publik: terjadwal, belum lewat, belum dihapus. */
    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('departures.status', 'scheduled')
            ->whereDate('departures.departure_date', '>=', now('Asia/Jakarta')->toDateString());
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(DeparturePrice::class)->orderByRaw("array_position(ARRAY['quad','triple','double']::varchar[], room_type)");
    }
}
