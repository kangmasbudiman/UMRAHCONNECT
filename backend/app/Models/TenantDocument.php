<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantDocument extends Model
{
    use BelongsToTenant, HasUuids;

    /** Dokumen wajib sebelum pengajuan verifikasi (BP-01). */
    public const REQUIRED_TYPES = ['akta', 'nib', 'npwp', 'ppiu_license', 'pic_ktp'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
