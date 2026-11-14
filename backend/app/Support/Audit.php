<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Catatan audit untuk tindakan sensitif (NFR keamanan; tabel audit_logs). */
class Audit
{
    public static function log(string $action, Model $entity, ?array $old = null, ?array $new = null, ?string $reason = null, ?string $tenantId = null, ?string $context = null): void
    {
        $req = request();
        $user = $req?->user();
        AuditLog::create([
            'tenant_id' => $tenantId ?? ($entity->getAttribute('tenant_id') ?? null),
            'actor_user_id' => $user?->id,
            'actor_context' => $context ?? ($user?->platform_role ? 'platform' : 'tenant'),
            'action' => $action,
            'entity_type' => $entity->getTable(),
            'entity_id' => $entity->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason,
            'ip_address' => $req?->ip(),
            'user_agent' => $req ? substr((string) $req->userAgent(), 0, 500) : null,
        ]);
    }
}
