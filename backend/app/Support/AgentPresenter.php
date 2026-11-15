<?php

namespace App\Support;

use App\Models\Agent;
use App\Models\CommissionScheme;
use App\Models\Partnership;
use App\Models\ReferralLink;

/** Bentuk respons AgentProfile, AgentRef, Partnership, CommissionScheme, ReferralLink (api/openapi.yaml). */
class AgentPresenter
{
    public static function mask(?string $v, int $head = 4, int $tail = 4): ?string
    {
        if (! $v) {
            return null;
        }
        $len = strlen($v);

        return $len <= $head + $tail ? str_repeat('•', $len) : substr($v, 0, $head).str_repeat('•', $len - $head - $tail).substr($v, -$tail);
    }

    public static function profile(Agent $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'display_name' => $a->user?->name,
            'nik_masked' => self::mask($a->nik_enc),
            'ktp_file_id' => $a->ktp_file_id,
            'domicile_city' => $a->domicile_city,
            'region' => Regions::describe($a),
            'address' => $a->address_enc,           // hanya untuk profil agen sendiri (tidak dipakai di halaman publik)
            'postal_code' => $a->postal_code,
            'payout_bank_name' => $a->payout_bank_name,
            'payout_account_no_masked' => self::mask($a->payout_account_no_enc, 0, 4),
            'payout_account_holder' => $a->payout_account_holder,
            'status' => $a->status,
            'whatsapp' => $a->user?->phone,
        ];
    }

    public static function agentRef(Agent $a, bool $withContact = false): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'display_name' => $a->user?->name, 'domicile_city' => $a->domicile_city]
            + ($withContact ? ['phone' => $a->user?->phone, 'email' => $a->user?->email] : []);
    }

    public static function scheme(?CommissionScheme $s): ?array
    {
        return $s ? [
            'id' => $s->id, 'scope' => $s->scope, 'package_id' => $s->package_id, 'agent_id' => $s->agent_id,
            'type' => $s->type, 'flat_amount' => $s->flat_amount, 'percent_bp' => $s->percent_bp, 'earn_on' => $s->earn_on,
            'effective_from' => $s->effective_from?->toIso8601String(), 'effective_to' => $s->effective_to?->toIso8601String(),
            'label' => Commission::label($s),
        ] : null;
    }

    public static function partnership(Partnership $p, bool $agentContact = false): array
    {
        $p->loadMissing(['agent.user', 'tenant']);

        return [
            'id' => $p->id,
            'tenant' => ['id' => $p->tenant->id, 'slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name, 'whatsapp' => $p->tenant->whatsapp],
            'agent' => self::agentRef($p->agent, $agentContact),
            'status' => $p->status,
            'source' => $p->source,
            'message' => $p->message,
            'reject_reason' => $p->reject_reason,
            'status_note' => $p->status_note,
            'commission_scheme' => self::scheme(Commission::effective($p->tenant_id, $p->agent_id)),
            'decided_at' => $p->decided_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    public static function link(ReferralLink $l): array
    {
        $l->loadMissing(['tenant', 'package' => fn ($q) => $q->withoutTenantScope(), 'partnership']);
        $path = $l->package ? "/paket/{$l->tenant->slug}/{$l->package->slug}" : "/ppiu/{$l->tenant->slug}";
        $usable = $l->is_active && $l->partnership?->status === 'active';

        return [
            'id' => $l->id,
            'tenant_id' => $l->tenant_id,
            'tenant_name' => $l->tenant->brand_name,
            'package_id' => $l->package_id,
            'package_name' => $l->package?->name,
            'code' => $l->code,
            'url' => rtrim(config('app.web_url'), '/').$path.'?ref='.$l->code,
            'click_count' => $l->click_count,
            'is_active' => $usable,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
