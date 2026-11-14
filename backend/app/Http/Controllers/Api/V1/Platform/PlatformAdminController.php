<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Package;
use App\Models\TenantMember;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\OffsetCursor;
use App\Support\Permissions;
use App\Support\Present;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Operasi platform lanjutan:
 * - FR-ADM-06 moderasi paket (turunkan / izinkan terbit lagi),
 * - FR-ADM-07 kelola akun (Admin Platform: hanya akun agen dan jamaah),
 * - kelola staf platform dan FR-ADM-09 pengaturan sistem (Super Admin).
 */
class PlatformAdminController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    // ---------- Moderasi paket ----------

    public function packages(Request $request): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', Rule::in(['draft', 'published', 'archived', 'taken_down'])], 'filter.tenant_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100']]);
        $q = Package::withoutTenantScope()->with('tenant')
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', ['published', 'taken_down']))
            ->when($request->input('filter.tenant_id'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->when($request->input('q'), fn ($q, $s) => $q->where('name', 'ilike', '%'.addcslashes($s, '%_\\').'%'))
            ->orderByRaw("CASE status WHEN 'taken_down' THEN 0 ELSE 1 END")->orderByDesc('updated_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 30);
        $counts = Package::withoutTenantScope()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => $rows->map(fn ($p) => $this->package($p))->values(), 'meta' => $meta + ['counts' => $counts]]);
    }

    public function takeDown(Request $request, string $package): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']], ['reason.min' => 'Tulis alasan yang jelas (minimal 10 karakter); alasan ini dikirim ke PPIU.']);
        $p = Package::withoutTenantScope()->with('tenant')->findOrFail($package);
        if ($p->status === 'taken_down') {
            throw ApiException::conflict('invalid_status_transition', 'Paket ini sudah diturunkan.');
        }
        $old = $p->status;
        $p->update(['status' => 'taken_down', 'taken_down_reason' => $data['reason']]);
        Audit::log('package.taken_down', $p, ['status' => $old], ['status' => 'taken_down'], $data['reason'], $p->tenant_id, 'platform');
        $this->notify->toTenant($p->tenant_id, ['owner', 'admin'], 'package.taken_down', ['body' => "Paket \"{$p->name}\" diturunkan dari marketplace oleh tim UMRAH CONNECT. Alasan: {$data['reason']}. Booking yang sudah ada tetap berjalan.", 'url' => "/dasbor/ppiu/{$p->tenant_id}/paket/{$p->id}"], true);

        return response()->json(['data' => $this->package($p->fresh('tenant'))]);
    }

    /** Pelanggaran sudah diperbaiki: paket kembali ke arsip, PPIU bisa menerbitkannya lagi. */
    public function restorePackage(Request $request, string $package): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $p = Package::withoutTenantScope()->with('tenant')->findOrFail($package);
        if ($p->status !== 'taken_down') {
            throw ApiException::conflict('invalid_status_transition', 'Paket ini tidak sedang diturunkan.');
        }
        $p->update(['status' => 'archived']);
        Audit::log('package.restored', $p, ['status' => 'taken_down'], ['status' => 'archived'], $data['reason'], $p->tenant_id, 'platform');
        $this->notify->toTenant($p->tenant_id, ['owner', 'admin'], 'package.restored', ['body' => "Paket \"{$p->name}\" sudah boleh diterbitkan lagi. Periksa isinya lalu terbitkan dari tab Paket.", 'url' => "/dasbor/ppiu/{$p->tenant_id}/paket/{$p->id}"], true);

        return response()->json(['data' => $this->package($p->fresh('tenant'))]);
    }

    private function package(Package $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'status' => $p->status, 'taken_down_reason' => $p->taken_down_reason,
            'published_at' => $p->published_at?->toIso8601String(), 'updated_at' => $p->updated_at?->toIso8601String(),
            'tenant' => ['id' => $p->tenant->id, 'slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name, 'verification_status' => $p->tenant->verification_status]];
    }

    // ---------- Akun ----------

    public function users(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'filter.status' => ['nullable', Rule::in(['unclaimed', 'active', 'locked', 'deactivated'])]]);
        $term = trim((string) $request->input('q'));
        $digits = preg_replace('/\D/', '', $term);
        $q = User::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')->orWhere('email', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', '%'.ltrim($digits, '0').'%'))))
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 30);

        return response()->json(['data' => $this->usersWithRoles($rows), 'meta' => $meta]);
    }

    public function deactivateUser(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->assertCanManage($request->user(), $user);
        if ($user->status === 'deactivated') {
            throw ApiException::conflict('already_deactivated', 'Akun ini sudah nonaktif.');
        }
        $old = $user->status;
        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => 'deactivated'])->save();
            // Sesi dan token dicabut agar akses langsung berhenti.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
        });
        Audit::log('user.deactivated', $user, ['status' => $old], ['status' => 'deactivated'], $data['reason'], null, 'platform');
        $this->notify->send($user, 'account.deactivated', ['body' => 'Akun UMRAH CONNECT Anda dinonaktifkan. Hubungi tim UMRAH CONNECT bila menurut Anda ini keliru.'], null, true);

        return response()->json(['data' => $this->usersWithRoles(collect([$user->fresh()]))[0]]);
    }

    public function reactivateUser(Request $request, User $user): JsonResponse
    {
        $this->assertCanManage($request->user(), $user);
        if ($user->status !== 'deactivated' && $user->status !== 'locked') {
            throw ApiException::conflict('not_deactivated', 'Akun ini sudah aktif.');
        }
        $old = $user->status;
        $user->forceFill(['status' => $user->password_hash ? 'active' : 'unclaimed', 'failed_login_count' => 0, 'locked_until' => null])->save();
        Audit::log('user.reactivated', $user, ['status' => $old], ['status' => $user->status], $request->input('reason'), null, 'platform');

        return response()->json(['data' => $this->usersWithRoles(collect([$user->fresh()]))[0]]);
    }

    /** Admin Platform hanya boleh mengelola akun agen dan jamaah; akun staf PPIU dan staf platform butuh Super Admin. */
    private function assertCanManage(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new ApiException(422, 'cannot_manage_self', 'Anda tidak bisa mengubah status akun sendiri.');
        }
        if ($target->platform_role === 'super_admin') {
            throw new ApiException(403, 'forbidden', 'Akun Super Admin tidak bisa diubah dari sini.');
        }
        if (Permissions::platformAllows($actor, 'platform.user.manage')) {
            return;
        }
        $isStaff = $target->platform_role !== null
            || TenantMember::withoutTenantScope()->where('user_id', $target->id)->whereIn('status', ['active', 'invited'])->exists();
        if ($isStaff) {
            throw new ApiException(403, 'forbidden', 'Akun staf PPIU atau staf platform hanya bisa diubah Super Admin.');
        }
    }

    private function usersWithRoles($users): array
    {
        $ids = $users->pluck('id');
        $members = TenantMember::withoutTenantScope()->with('tenant')->whereIn('user_id', $ids)->whereIn('status', ['active', 'invited'])->get()->groupBy('user_id');
        $agents = Agent::whereIn('user_id', $ids)->get()->keyBy('user_id');

        return $users->map(fn (User $u) => Present::user($u) + [
            'created_at' => $u->created_at?->toIso8601String(),
            'agent' => isset($agents[$u->id]) ? ['code' => $agents[$u->id]->code, 'status' => $agents[$u->id]->status] : null,
            'memberships' => ($members[$u->id] ?? collect())->map(fn ($m) => ['tenant_id' => $m->tenant_id, 'brand_name' => $m->tenant?->brand_name, 'role' => $m->role, 'status' => $m->status])->values(),
        ])->values()->all();
    }

    // ---------- Staf platform (Super Admin) ----------

    public function admins(): JsonResponse
    {
        $rows = User::whereNotNull('platform_role')->orderByRaw("CASE platform_role WHEN 'super_admin' THEN 0 ELSE 1 END")->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($u) => Present::user($u))->values()]);
    }

    public function addAdmin(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'uuid']]);
        $user = User::find($data['user_id']) ?? throw new ApiException(422, 'validation_failed', 'Akun tidak ditemukan.', errors: ['user_id' => ['Tidak ditemukan.']]);
        if ($user->platform_role) {
            throw ApiException::conflict('already_staff', 'Akun ini sudah staf platform.');
        }
        if ($user->status !== 'active' || ! $user->email || ! $user->email_verified_at) {
            throw new ApiException(422, 'user_not_eligible', 'Staf platform harus memakai akun aktif dengan email terverifikasi.');
        }
        $user->forceFill(['platform_role' => 'platform_admin'])->save();
        Audit::log('platform_user.added', $user, null, ['platform_role' => 'platform_admin'], null, null, 'platform');
        $this->notify->send($user, 'platform.role_granted', ['title' => 'Anda menjadi Admin Platform', 'body' => 'Akun Anda kini Admin Platform UMRAH CONNECT. Aktifkan verifikasi dua langkah (2FA) sebelum membuka dasbor platform.', 'url' => '/akun#keamanan'], null, true);

        return response()->json(['data' => Present::user($user->fresh())], 201);
    }

    public function removeAdmin(Request $request, User $user): Response
    {
        if ($user->id === $request->user()->id) {
            throw new ApiException(422, 'cannot_manage_self', 'Anda tidak bisa mencabut peran sendiri.');
        }
        if ($user->platform_role !== 'platform_admin') {
            throw new ApiException(422, 'not_platform_admin', $user->platform_role === 'super_admin' ? 'Peran Super Admin tidak bisa dicabut dari sini.' : 'Akun ini bukan Admin Platform.');
        }
        $user->forceFill(['platform_role' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('support_sessions')->where('admin_user_id', $user->id)->where('expires_at', '>', now())->whereNull('ended_at')->update(['ended_at' => now()]);
        Audit::log('platform_user.removed', $user, ['platform_role' => 'platform_admin'], ['platform_role' => null], null, null, 'platform');

        return response()->noContent();
    }

    // ---------- Pengaturan (Super Admin) ----------

    public function settings(): JsonResponse
    {
        return response()->json(['data' => collect(array_keys(Settings::EDITABLE))->map(fn ($k) => Settings::present($k))->values()]);
    }

    public function setting(string $key): JsonResponse
    {
        abort_unless(isset(Settings::EDITABLE[$key]), 404);

        return response()->json(['data' => Settings::present($key)]);
    }

    public function putSetting(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(Settings::EDITABLE[$key]), 404);
        $data = $request->validate(Settings::rules($key));
        $old = Settings::get($key);
        $value = is_numeric($data['value']) && ! is_string(Settings::EDITABLE[$key][2]) ? (int) $data['value'] : $data['value'];
        Settings::put($key, $value, $request->user()->id);
        Audit::log('setting.updated', $request->user(), ['key' => $key, 'value' => $old], ['key' => $key, 'value' => $value], null, null, 'platform');

        return response()->json(['data' => Settings::present($key)]);
    }
}
