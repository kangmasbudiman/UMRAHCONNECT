<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\TenantMember;
use App\Models\User;

/** Bentuk respons sesuai skema components di api/openapi.yaml. */
class Present
{
    public static function user(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'status' => $u->status,
            'two_factor_enabled' => (bool) $u->two_factor_enabled,
            'platform_role' => $u->platform_role,
            'last_login_at' => $u->last_login_at?->toIso8601String(),
            'email_verified' => $u->email_verified_at !== null,
            'phone_verified' => $u->phone_verified_at !== null,
        ];
    }

    public static function tenant(Tenant $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'slug' => $t->slug,
            'legal_name' => $t->legal_name,
            'brand_name' => $t->brand_name,
            'ppiu_license_no' => $t->ppiu_license_no,
            'license_expires_on' => $t->license_expires_on?->toDateString(),
            'nib' => $t->nib,
            'npwp' => $t->npwp,
            'address' => $t->address,
            'city' => $t->city,
            'province' => $t->province,
            'postal_code' => $t->postal_code,
            'region' => Regions::describe($t),
            'phone' => $t->phone,
            'whatsapp' => $t->whatsapp,
            'email' => $t->email,
            'logo_file_id' => $t->logo_file_id,
            'verification_status' => $t->verification_status,
            'verified_at' => $t->verified_at?->toIso8601String(),
            'status_note' => $t->status_note,
            'settings' => (object) ($t->settings ?? []),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    public static function document(TenantDocument $d): array
    {
        return [
            'id' => $d->id,
            'type' => $d->type,
            'file_id' => $d->file_id,
            'file_name' => $d->file?->original_name,
            'status' => $d->status,
            'review_note' => $d->review_note,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    public static function bankAccount(BankAccount $a): array
    {
        return [
            'id' => $a->id,
            'bank_name' => $a->bank_name,
            'account_no' => $a->account_no,
            'account_holder' => $a->account_holder,
            'status' => $a->status,
            'is_default' => (bool) $a->is_default,
        ];
    }

    public static function member(TenantMember $m): array
    {
        return [
            'id' => $m->id,
            'user' => self::user($m->user),
            'role' => $m->role,
            'status' => $m->status,
            'invited_at' => $m->invited_at?->toIso8601String(),
            'joined_at' => $m->joined_at?->toIso8601String(),
        ];
    }
}
