<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\TenantMember;
use App\Support\Audit;
use App\Support\Otp\OtpService;
use App\Support\Present;
use App\Support\Regions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pendaftaran dan profil PPIU (BP-01, FR-PPIU-01, ST-TENANT). */
class TenantController extends Controller
{
    private const LOCKED_FIELDS = ['legal_name', 'ppiu_license_no', 'license_expires_on'];

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null, true);

        $tenant = DB::transaction(function () use ($data, $request) {
            $tenant = Tenant::create($data + [
                'code' => $this->uniqueCode($data['brand_name']),
                'slug' => $this->uniqueSlug($data['brand_name']),
                'verification_status' => 'draft',
                'settings' => ['dp_deadline_hours_default' => (int) \App\Support\Settings::get('booking.dp_deadline_hours_default'), 'commission_earn_on_default' => \App\Support\Settings::get('commission.earn_on_default')],
            ]);
            TenantMember::create([
                'tenant_id' => $tenant->id,
                'user_id' => $request->user()->id,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ]);
            Audit::log('tenant.registered', $tenant, null, ['brand_name' => $tenant->brand_name], tenantId: $tenant->id, context: 'tenant');

            return $tenant;
        });

        return response()->json(['data' => $this->present($tenant->fresh())], 201);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        return response()->json(['data' => $this->present($tenant)]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        if (! $tenant->canBeEditedByOwner()) {
            throw ApiException::conflict('tenant_not_editable', match ($tenant->verification_status) {
                'pending_review' => 'Profil sedang ditinjau tim UMRAH CONNECT dan belum bisa diubah.',
                default => 'Profil PPIU dengan status ini tidak bisa diubah.',
            });
        }
        $data = $this->validated($request, $tenant, false);
        if ($tenant->licenseLocked()) {
            $changed = array_filter(self::LOCKED_FIELDS, fn ($f) => array_key_exists($f, $data) && (string) $data[$f] !== (string) ($f === 'license_expires_on' ? $tenant->license_expires_on?->toDateString() : $tenant->{$f}));
            if ($changed) {
                throw new ApiException(422, 'license_fields_locked', 'Nama badan usaha dan data izin PPIU terkunci setelah terverifikasi. Ajukan perubahan lewat tim UMRAH CONNECT.', errors: array_fill_keys(array_values($changed), ['Terkunci setelah verifikasi.']));
            }
        }
        $old = $tenant->only(array_keys($data));
        $tenant->update($data);
        Audit::log('tenant.profile_updated', $tenant, $old, $data, tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => $this->present($tenant->fresh())]);
    }

    /** draft/needs_revision → pending_review bila syarat lengkap (BP-01). */
    public function submit(Request $request, Tenant $tenant): JsonResponse
    {
        if (! in_array($tenant->verification_status, ['draft', 'needs_revision'], true)) {
            throw ApiException::conflict('invalid_status_transition', 'Pengajuan verifikasi hanya bisa dikirim dari status Draf atau Perlu Revisi.');
        }
        $checklist = $this->checklist($tenant);
        if (! $checklist['can_submit']) {
            throw ApiException::unprocessable('verification_incomplete', 'Lengkapi data berikut sebelum mengirim pengajuan.', ['checklist' => $checklist]);
        }
        $old = $tenant->verification_status;
        $tenant->update(['verification_status' => 'pending_review', 'status_note' => null]);
        Audit::log('tenant.verification_submitted', $tenant, ['verification_status' => $old], ['verification_status' => 'pending_review'], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => $this->present($tenant->fresh())]);
    }

    /** Status kelengkapan onboarding (tambahan di luar skema Tenant, dipakai layar PPIU-01). */
    public static function checklist(Tenant $tenant): array
    {
        $latest = TenantDocument::withoutTenantScope()->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')->orderByDesc('id')->get()->unique('type')->keyBy('type');
        $missing = array_values(array_filter(TenantDocument::REQUIRED_TYPES, fn ($t) => ! isset($latest[$t]) || $latest[$t]->status === 'rejected'));
        $hasBank = BankAccount::withoutTenantScope()->where('tenant_id', $tenant->id)->where('status', '!=', 'inactive')->exists();
        $profile = $tenant->legal_name && $tenant->ppiu_license_no && $tenant->license_expires_on && $tenant->address && $tenant->village_code && $tenant->phone && $tenant->email;
        $licenseValid = $tenant->license_expires_on && $tenant->license_expires_on->isAfter(now('Asia/Jakarta')->startOfDay());

        return [
            'profile_complete' => (bool) $profile,
            'license_valid' => (bool) $licenseValid,
            'documents_missing' => $missing,
            'has_bank_account' => $hasBank,
            'can_submit' => $profile && $licenseValid && ! $missing && $hasBank,
        ];
    }

    private function present(Tenant $tenant): array
    {
        return Present::tenant($tenant) + ['onboarding' => self::checklist($tenant)];
    }

    private function validated(Request $request, ?Tenant $tenant, bool $creating): array
    {
        foreach (['phone', 'whatsapp'] as $f) {
            if ($request->filled($f)) {
                $request->merge([$f => OtpService::normalizePhone($request->input($f))]);
            }
        }
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'legal_name' => [$req, 'string', 'max:200'],
            'brand_name' => [$req, 'string', 'max:200'],
            'ppiu_license_no' => [$req, 'string', 'max:100', Rule::unique('tenants', 'ppiu_license_no')->ignore($tenant?->id)],
            'license_expires_on' => [$req, 'date_format:Y-m-d', 'after:today'],
            'nib' => ['nullable', 'string', 'max:30'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'address' => [$req, 'string', 'max:500'],
            // Alamat nasional: kode kelurahan/desa dari dropdown; provinsi–kab/kota–kecamatan diturunkan di server.
            'region_code' => [$req, 'string', 'max:13'],
            'postal_code' => ['nullable', 'regex:/^[0-9]{5}$/'],
            'phone' => [$req, 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'whatsapp' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'email' => [$req, 'email:rfc', 'max:254'],
        ], [
            'ppiu_license_no.unique' => 'Nomor izin PPIU ini sudah terdaftar. Bila ini milik Anda, hubungi tim UMRAH CONNECT.',
            'license_expires_on.after' => 'Izin PPIU sudah tidak berlaku. Perbarui izin terlebih dahulu.',
            'phone.regex' => 'Nomor telepon tidak valid. Contoh: 0741123456 atau 081234567890.',
            'whatsapp.regex' => 'Nomor WhatsApp tidak valid. Contoh: 081234567890.',
            'region_code.required' => 'Pilih alamat sampai kelurahan/desa.',
            'postal_code.regex' => 'Kode pos terdiri dari 5 angka.',
        ]);
        if (isset($data['region_code'])) {
            $r = Regions::resolveVillage($data['region_code']);
            unset($data['region_code']);
            // Kolom teks lama tetap diisi untuk tampilan & pencarian sederhana.
            $data += Regions::columns($r) + ['city' => $r['regency_name'], 'province' => $r['province_name']];
        }

        return $data;
    }

    private function uniqueSlug(string $brand): string
    {
        $base = Str::slug($brand) ?: 'ppiu';
        $slug = $base;
        for ($i = 2; Tenant::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function uniqueCode(string $brand): string
    {
        $letters = Str::upper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($brand)), 0, 5)) ?: 'PPIU';
        do {
            $code = $letters.random_int(10, 99);
        } while (Tenant::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
