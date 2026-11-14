<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\OffsetCursor;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Dasbor platform, audit log, dan akses dukungan (FR-ADM-01, FR-ADM-08, support access). */
class PlatformOpsController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $weeks = DB::select("
            select to_char(date_trunc('week', created_at at time zone 'Asia/Jakarta'), 'YYYY-MM-DD') as week, count(*) as bookings,
                   coalesce(sum(total_amount) filter (where status not in ('expired','cancelled')), 0) as gmv
            from bookings where created_at >= now() - interval '8 weeks' group by 1 order by 1");

        return response()->json(['data' => [
            'tenants_by_status' => Tenant::query()->selectRaw('verification_status, count(*) c')->groupBy('verification_status')->pluck('c', 'verification_status'),
            'agents' => Agent::count(),
            'users' => User::count(),
            'bookings_by_status' => Booking::withoutTenantScope()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'gmv_active' => (int) Booking::withoutTenantScope()->whereNotIn('status', ['expired', 'cancelled'])->sum('total_amount'),
            'payments_verified_amount' => (int) Payment::withoutTenantScope()->where('status', 'verified')->sum('verified_amount'),
            'weekly' => array_map(fn ($w) => ['week' => $w->week, 'bookings' => (int) $w->bookings, 'gmv' => (int) $w->gmv], $weeks),
        ]]);
    }

    /** Super Admin: semua log. Admin Platform: hanya log tenant (platform.audit_log.view:tenant). */
    public function auditLogs(Request $request): JsonResponse
    {
        $request->validate(['filter.tenant_id' => ['nullable', 'uuid'], 'filter.actor_user_id' => ['nullable', 'uuid'], 'filter.action' => ['nullable', 'string', 'max:60'],
            'filter.entity_type' => ['nullable', 'string', 'max:50'], 'filter.from' => ['nullable', 'date'], 'filter.to' => ['nullable', 'date']]);
        $onlyTenant = ! Permissions::platformAllows($request->user(), 'platform.audit_log.view');
        $q = AuditLog::query()
            ->when($onlyTenant, fn ($q) => $q->whereNotNull('tenant_id'))
            ->when($request->input('filter.tenant_id'), fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($request->input('filter.actor_user_id'), fn ($q, $v) => $q->where('actor_user_id', $v))
            ->when($request->input('filter.action'), fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($request->input('filter.entity_type'), fn ($q, $v) => $q->where('entity_type', $v))
            ->when($request->input('filter.from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->input('filter.to'), fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 50, 200);
        $users = User::whereIn('id', $rows->pluck('actor_user_id')->filter())->pluck('name', 'id');
        $tenants = Tenant::whereIn('id', $rows->pluck('tenant_id')->filter())->pluck('brand_name', 'id');

        return response()->json(['data' => $rows->map(fn ($l) => [
            'id' => $l->id, 'action' => $l->action, 'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id,
            'tenant_id' => $l->tenant_id, 'tenant_name' => $tenants[$l->tenant_id] ?? null,
            'actor_user_id' => $l->actor_user_id, 'actor_name' => $users[$l->actor_user_id] ?? null, 'actor_context' => $l->actor_context,
            'old_values' => $l->old_values, 'new_values' => $l->new_values, 'reason' => $l->reason, 'ip_address' => $l->ip_address,
            'created_at' => $l->created_at?->toIso8601String(),
        ])->values(), 'meta' => $meta]);
    }

    public function supportSessions(Request $request): JsonResponse
    {
        $rows = SupportSession::where('admin_user_id', $request->user()->id)->orderByDesc('started_at')->limit(50)->get();
        $tenants = Tenant::whereIn('id', $rows->pluck('tenant_id'))->pluck('brand_name', 'id');

        return response()->json(['data' => $rows->map(fn ($s) => self::presentSession($s) + ['tenant_name' => $tenants[$s->tenant_id] ?? null])->values()]);
    }

    /** Akses baca-saja (lama sesi: pengaturan support_access.max_minutes, bawaan 60 menit) ke data satu PPIU, Owner diberi tahu, setiap akses tercatat. */
    public function openSupport(Request $request, NotificationService $notify): JsonResponse
    {
        $d = $request->validate(['tenant_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'ticket_no' => ['required', 'string', 'max:50']]);
        $tenant = Tenant::findOrFail($d['tenant_id']);
        $s = SupportSession::create(['admin_user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'reason' => $d['reason'], 'ticket_no' => $d['ticket_no'],
            'started_at' => now(), 'expires_at' => now()->addMinutes((int) \App\Support\Settings::get('support_access.max_minutes'))]);
        Audit::log('support.opened', $s, null, ['ticket_no' => $d['ticket_no']], $d['reason'], $tenant->id, 'platform');
        $notify->toTenant($tenant->id, ['owner'], 'support.opened', ['body' => "Tim UMRAH CONNECT ({$request->user()->name}) membuka akses baca-saja ".(int) round($s->started_at->diffInMinutes($s->expires_at))." menit untuk tiket {$d['ticket_no']}: {$d['reason']}"], true);

        return response()->json(['data' => self::presentSession($s)], 201);
    }

    public function endSupport(Request $request, string $session): Response
    {
        $s = SupportSession::where('admin_user_id', $request->user()->id)->whereKey($session)->firstOrFail();
        if ($s->ended_at) {
            throw ApiException::conflict('already_ended', 'Sesi ini sudah diakhiri.');
        }
        $s->update(['ended_at' => now()]);
        Audit::log('support.ended', $s, null, null, null, $s->tenant_id, 'platform');

        return response()->noContent();
    }

    private static function presentSession(SupportSession $s): array
    {
        return ['id' => $s->id, 'tenant_id' => $s->tenant_id, 'reason' => $s->reason, 'ticket_no' => $s->ticket_no,
            'started_at' => $s->started_at->toIso8601String(), 'expires_at' => $s->expires_at->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(), 'active' => $s->isActive()];
    }
}
