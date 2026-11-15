<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['nik_enc', 'nik_hash', 'payout_account_no_enc'];

    protected function casts(): array
    {
        return [
            'nik_enc' => 'encrypted',
            'payout_account_no_enc' => 'encrypted',
            'address_enc' => 'encrypted',
        ];
    }

    /** Blind index NIK: HMAC-SHA256 dengan kunci terpisah (Database Design §12). */
    public static function nikHash(string $nik): string
    {
        return hash_hmac('sha256', preg_replace('/\D/', '', $nik), config('app.blind_index_key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function partnerships(): HasMany
    {
        return $this->hasMany(Partnership::class);
    }
}
