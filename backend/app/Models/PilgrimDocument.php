<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PilgrimDocument extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function file(): BelongsTo { return $this->belongsTo(File::class); }

    public function reviews(): HasMany { return $this->hasMany(PilgrimDocumentReview::class); }
}
