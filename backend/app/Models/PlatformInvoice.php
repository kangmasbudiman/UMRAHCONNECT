<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tagihan platform ke PPIU/agen (bukan ke jamaah). */
class PlatformInvoice extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'issued_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlatformInvoiceItem::class, 'invoice_id');
    }
}
