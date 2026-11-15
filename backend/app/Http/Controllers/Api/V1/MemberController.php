<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Tim PPIU (FR-AUTH-07): Owner mengundang Admin/Finance. */
class MemberController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $rows = TenantMember::with('user')->where('tenant_id', $tenant->id)
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 ELSE 2 END")->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($m) => Present::member($m))->values()]);
    }

    /**
     * Undangan lewat email. Penerima harus sudah punya akun (users tanpa kata sandi hanya boleh
     * berstatus unclaimed dengan nomor HP — Database Design v1.2), lalu menerima undangan di menu Akun.
     */
    public function invite(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc'], 'role' => ['required', 'in:admin,finance']]);
        $user = User::where('email', Str::lower($data['email']))->first()
            ?? throw new ApiException(422, 'invitee_not_registered', 'Email ini belum terdaftar. Minta yang bersangkutan membuat akun UMRAH CONNECT dengan email ini, lalu undang lagi.', errors: ['email' => ['Belum terdaftar.']]);

        $max = (int) (app(\App\Services\BillingService::class)->feature('tenant', $tenant->id, 'max_members') ?? 3);
        if (TenantMember::where('tenant_id', $tenant->id)->whereIn('status', ['active', 'invited'])->count() >= $max) {
            throw new ApiException(402, 'plan_required', "Paket Anda dibatasi {$max} anggota tim (termasuk Owner). Tingkatkan ke PPIU Business di tab Langganan, atau nonaktifkan anggota yang tidak aktif.");
        }
        $member = TenantMember::where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
        if ($member && $member->status !== 'deactivated') {
            throw ApiException::conflict('already_member', $member->status === 'invited' ? 'Pengguna ini sudah diundang dan belum menjawab.' : 'Pengguna ini sudah menjadi anggota tim.');
        }
        $member = DB::transaction(function () use ($member, $tenant, $user, $data, $request) {
            $attrs = ['role' => $data['role'], 'status' => 'invited', 'invited_by' => $request->user()->id, 'invited_at' => now(), 'joined_at' => null];

            return $member ? tap($member)->update($attrs) : TenantMember::create($attrs + ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        });
        Mail::raw("Anda diundang bergabung sebagai {$data['role']} di {$tenant->brand_name} pada UMRAH CONNECT. Masuk ke akun Anda lalu buka menu Akun untuk menerima undangan.", fn ($m) => $m->to($user->email)->subject("Undangan tim {$tenant->brand_name}"));
        Audit::log('member.invited', $member, null, ['role' => $data['role'], 'user_id' => $user->id], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => Present::member($member->load('user'))], 201);
    }

    public function update(Request $request, Tenant $tenant, string $member): JsonResponse
    {
        $m = $this->find($tenant, $member);
        $data = $request->validate(['role' => ['required', 'in:admin,finance']]);
        $old = $m->role;
        $m->update(['role' => $data['role']]);
        Audit::log('member.role_changed', $m, ['role' => $old], ['role' => $data['role']], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => Present::member($m->load('user'))]);
    }

    public function deactivate(Request $request, Tenant $tenant, string $member): JsonResponse
    {
        $m = $this->find($tenant, $member);
        $m->update(['status' => 'deactivated']);
        // Sesi dicabut agar akses tenant langsung berhenti.
        DB::table('sessions')->where('user_id', $m->user_id)->delete();
        Audit::log('member.deactivated', $m, null, ['user_id' => $m->user_id], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => Present::member($m->load('user'))]);
    }

    /** Penerima undangan menerima / menolak (endpoint tambahan /me/invitations/*). */
    public function respond(Request $request, string $member, string $decision): JsonResponse
    {
        $m = TenantMember::withoutTenantScope()->with('user')->whereKey($member)
            ->where('user_id', $request->user()->id)->where('status', 'invited')->firstOrFail();
        $m->update($decision === 'accept' ? ['status' => 'active', 'joined_at' => now()] : ['status' => 'deactivated']);
        Audit::log($decision === 'accept' ? 'member.joined' : 'member.declined', $m, null, null, tenantId: $m->tenant_id, context: 'tenant');

        return response()->json(['data' => Present::member($m)]);
    }

    /**
     * Pengalihan kepemilikan (User Role §5): hanya Owner, ke anggota aktif (Admin/Finance), wajib kata sandi dan kode 2FA.
     * Owner lama menjadi Admin. Tercatat di audit log; kedua pihak diberi tahu lewat email.
     */
    public function transferOwnership(Request $request, Tenant $tenant, \App\Support\TwoFactor $twoFactor, \App\Services\NotificationService $notify): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'uuid'],
            'password' => ['required', 'string'],
            'two_factor_code' => ['required', 'string', 'max:20'],
        ], ['password.required' => 'Masukkan kata sandi Anda.', 'two_factor_code.required' => 'Masukkan kode dari aplikasi autentikator.']);
        $me = $request->user();
        if (! $me->two_factor_enabled) {
            throw new ApiException(422, 'two_factor_not_enabled', 'Aktifkan verifikasi dua langkah (2FA) di halaman Akun sebelum mengalihkan kepemilikan.');
        }
        if (! \Illuminate\Support\Facades\Hash::check($data['password'], (string) $me->password_hash)) {
            throw new ApiException(422, 'password_invalid', 'Kata sandi salah.', errors: ['password' => ['Kata sandi salah.']]);
        }
        if (! $twoFactor->verifyUser($me, $data['two_factor_code'])) {
            throw new ApiException(422, 'two_factor_invalid', 'Kode 2FA tidak cocok.', errors: ['two_factor_code' => ['Kode tidak cocok.']]);
        }

        [$old, $new] = DB::transaction(function () use ($tenant, $data, $me) {
            $old = TenantMember::where('tenant_id', $tenant->id)->where('user_id', $me->id)->where('role', 'owner')->where('status', 'active')->lockForUpdate()->firstOrFail();
            $new = TenantMember::with('user')->where('tenant_id', $tenant->id)->whereKey($data['member_id'])->lockForUpdate()->first();
            if (! $new || $new->id === $old->id || $new->status !== 'active') {
                throw new ApiException(422, 'member_not_eligible', 'Pilih anggota tim yang aktif (Admin atau Finance).', errors: ['member_id' => ['Bukan anggota aktif.']]);
            }
            if ($new->user->status !== 'active') {
                throw new ApiException(422, 'member_not_eligible', 'Akun anggota ini tidak aktif.', errors: ['member_id' => ['Akun tidak aktif.']]);
            }
            $prevRole = $new->role;
            // Indeks unik "satu Owner aktif per tenant": turunkan Owner lama dulu.
            $old->update(['role' => 'admin']);
            $new->update(['role' => 'owner']);
            Audit::log('tenant.ownership_transferred', $tenant, ['owner_user_id' => $me->id, 'new_owner_previous_role' => $prevRole],
                ['owner_user_id' => $new->user_id], null, $tenant->id, 'tenant');

            return [$old, $new];
        });

        $notify->send($new->user, 'tenant.ownership_received', ['body' => "Anda kini Owner {$tenant->brand_name} (dialihkan oleh {$me->name}). Owner wajib memakai 2FA; aktifkan di halaman Akun bila belum.", 'url' => "/dasbor/ppiu/{$tenant->id}"], $tenant->id, true);
        $notify->send($me, 'tenant.ownership_transferred', ['body' => "Kepemilikan {$tenant->brand_name} sudah dialihkan ke {$new->user->name}. Peran Anda sekarang Admin.", 'url' => "/dasbor/ppiu/{$tenant->id}"], $tenant->id, true);

        return $this->index($tenant);
    }

    private function find(Tenant $tenant, string $id): TenantMember
    {
        $m = TenantMember::where('tenant_id', $tenant->id)->whereKey($id)->firstOrFail();
        if ($m->role === 'owner') {
            throw ApiException::unprocessable('owner_immutable', 'Peran Owner hanya bisa dialihkan lewat pengalihan kepemilikan.');
        }

        return $m;
    }
}
