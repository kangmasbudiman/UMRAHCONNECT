<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantMember;
use App\Support\Permissions;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => Present::user($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        // Ganti email/HP butuh verifikasi ulang — menyusul; Sprint 1 hanya nama.
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $request->user()->update($data);

        return response()->json(['data' => Present::user($request->user()->fresh())]);
    }

    /** Konteks dan permission untuk membangun menu (skema Contexts). */
    public function contexts(Request $request): JsonResponse
    {
        $user = $request->user();
        $members = TenantMember::withoutTenantScope()->with('tenant')
            ->where('user_id', $user->id)->whereIn('status', ['active', 'invited'])->get();

        $tenants = $members->where('status', 'active')->map(fn ($m) => [
            'id' => $m->tenant_id,
            'brand_name' => $m->tenant->brand_name,
            'role' => $m->role,
            'tenant_status' => $m->tenant->verification_status,
            'permissions' => Permissions::forTenantRole($m->role),
        ])->values();

        $invitations = $members->where('status', 'invited')->map(fn ($m) => [
            'member_id' => $m->id,
            'tenant_id' => $m->tenant_id,
            'brand_name' => $m->tenant->brand_name,
            'role' => $m->role,
            'invited_at' => $m->invited_at?->toIso8601String(),
        ])->values();

        $agent = $user->agent;

        return response()->json(['data' => [
            'user' => Present::user($user),
            'tenants' => $tenants,
            'invitations' => $invitations,
            'agent' => $agent ? ['id' => $agent->id, 'code' => $agent->code, 'active_partnerships' => $agent->partnerships()->where('status', 'active')->count()] : null,
            'pilgrim' => ['managed_pilgrims' => 0, 'active_bookings' => 0],
            'platform_role' => $user->platform_role,
            'platform_permissions' => $user->platform_role ? array_values(config("permissions.platform.{$user->platform_role}")) : [],
            'two_factor_required' => Permissions::twoFactorRequired($user) && ! $user->two_factor_enabled && Permissions::enforceTwoFactor(),
            'contact_verified' => $user->email_verified_at !== null || $user->phone_verified_at !== null,
        ]]);
    }
}
