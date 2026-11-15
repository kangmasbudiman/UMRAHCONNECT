<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Biaya teknologi per booking lunas, ditagihkan ke PPIU (tidak pernah ke jamaah). */
class TransactionFee extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['accrued_at' => 'datetime'];
    }
}
