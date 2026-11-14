<?php

namespace App\Support;

use App\Models\CommissionScheme;

/** CR-02: skema komisi berlaku = agen+paket > agen > paket > default, hanya yang aktif (effective_to null). */
class Commission
{
    public static function effective(string $tenantId, ?string $agentId, ?string $packageId = null): ?CommissionScheme
    {
        $rows = CommissionScheme::withoutTenantScope()->where('tenant_id', $tenantId)->whereNull('effective_to')
            ->where(function ($q) use ($agentId, $packageId) {
                $q->where('scope', 'default');
                if ($packageId) {
                    $q->orWhere(fn ($w) => $w->where('scope', 'package')->where('package_id', $packageId));
                }
                if ($agentId) {
                    $q->orWhere(fn ($w) => $w->where('scope', 'agent')->where('agent_id', $agentId));
                    if ($packageId) {
                        $q->orWhere(fn ($w) => $w->where('scope', 'agent_package')->where('agent_id', $agentId)->where('package_id', $packageId));
                    }
                }
            })->get();
        foreach (['agent_package', 'agent', 'package', 'default'] as $scope) {
            if ($hit = $rows->firstWhere('scope', $scope)) {
                return $hit;
            }
        }

        return null;
    }

    /** Teks ringkas untuk UI, mis. "Rp1.000.000 per jamaah" atau "2,5% dari harga". */
    public static function label(?CommissionScheme $s): ?string
    {
        if (! $s) {
            return null;
        }

        return $s->type === 'flat'
            ? 'Rp'.number_format($s->flat_amount, 0, ',', '.').' per jamaah'
            : rtrim(rtrim(number_format($s->percent_bp / 100, 2, ',', '.'), '0'), ',').'% dari harga paket';
    }
}
