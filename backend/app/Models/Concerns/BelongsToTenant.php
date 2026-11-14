<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scope otomatis tenant_id untuk model milik tenant (Database Design §3).
 * Saat TenantContext terisi, semua query disaring ke tenant aktif dan tenant_id diisi otomatis saat insert.
 * Query lintas tenant (marketplace publik, platform) harus memanggil withoutTenantScope() secara eksplisit.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $ctx = app(TenantContext::class);
            if ($ctx->has()) {
                $query->where($query->getModel()->qualifyColumn('tenant_id'), $ctx->id());
            }
        });

        static::creating(function ($model) {
            $ctx = app(TenantContext::class);
            if ($ctx->has() && empty($model->tenant_id)) {
                $model->tenant_id = $ctx->id();
            }
        });
    }

    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
