<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'itinerary' => 'array',
            'facilities' => 'array',
            'includes' => 'array',
            'excludes' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** Paket yang boleh tampil publik: terbit dan PPIU-nya terverifikasi (BR-01, FR-MKT-01). */
    public function scopePubliclyVisible(Builder $q): Builder
    {
        return $q->withoutTenantScope()
            ->where('packages.status', 'published')
            ->whereHas('tenant', fn (Builder $t) => $t->verified());
    }

    public function departures(): HasMany
    {
        return $this->hasMany(Departure::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(PackageImage::class)->orderBy('sort_order');
    }
}
