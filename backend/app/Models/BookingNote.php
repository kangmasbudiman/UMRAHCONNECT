<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingNote extends Model
{
    use BelongsToTenant, HasUuids;

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
