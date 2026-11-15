<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Data jamaah milik pengelola (pemesan). NIK & paspor terenkripsi + blind index NIK (Database Design §12). */
class Pilgrim extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['nik_enc', 'nik_hash', 'passport_no_enc'];

    protected function casts(): array
    {
        return [
            'nik_enc' => 'encrypted',
            'passport_no_enc' => 'encrypted',
            'address_enc' => 'encrypted',
            'birth_date' => 'date',
            'passport_expires_on' => 'date',
            'emergency_contact' => 'array',
        ];
    }
}
