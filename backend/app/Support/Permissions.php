<?php

namespace App\Support;

use App\Models\User;

/** Membaca matriks config/permissions.php. */
class Permissions
{
    /** Daftar permission efektif untuk peran tenant (wildcard sudah dibuka). */
    public static function forTenantRole(string $role): array
    {
        $catalog = config('permissions.tenant_catalog');
        $patterns = config("permissions.tenant.$role", []);

        return array_values(array_filter($catalog, fn ($p) => self::matchesAny($p, $patterns)));
    }

    public static function tenantRoleAllows(string $role, string $permission): bool
    {
        return in_array($permission, config('permissions.tenant_catalog'), true)
            && self::matchesAny($permission, config("permissions.tenant.$role", []));
    }

    public static function platformAllows(User $user, string $permission): bool
    {
        return $user->platform_role !== null
            && self::matchesAny($permission, config("permissions.platform.{$user->platform_role}", []));
    }

    /** Apakah 2FA wajib bagi pengguna ini (platform role atau peran tenant aktif tertentu). */
    public static function twoFactorRequired(User $user): bool
    {
        $req = config('permissions.two_factor_required');
        if ($user->platform_role && in_array($user->platform_role, $req['platform'], true)) {
            return true;
        }

        return $user->memberships()->withoutTenantScope()->where('status', 'active')
            ->whereIn('role', $req['tenant'])->exists();
    }

    public static function enforceTwoFactor(): bool
    {
        return (bool) config('permissions.enforce_two_factor');
    }

    private static function matchesAny(string $permission, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if ($p === '*' || $p === $permission) {
                return true;
            }
            if (str_ends_with($p, '.*') && str_starts_with($permission, substr($p, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
