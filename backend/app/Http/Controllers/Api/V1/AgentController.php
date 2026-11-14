<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\ReferralLink;
use App\Models\Tenant;
use App\Support\AgentPresenter as AP;
use App\Support\Audit;
use App\Support\Commission;
use App\Support\OffsetCursor;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Konteks agen: profil, PPIU, paket mitra, link referral + QR (FR-AGT-01..06, BP-03, BP-04). */
class AgentController extends Controller
{
    public function showProfile(Request $request): JsonResponse
    {
        $agent = $request->user()->agent()->with('user')->first()
            ?? throw new ApiException(404, 'agent_not_registered', 'Anda belum terdaftar sebagai agen.');

        return response()->json(['data' => AP::profile($agent)]);
    }

    public function register(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->agent()->exists()) {
            throw ApiException::conflict('agent_exists', 'Akun ini sudah terdaftar sebagai agen.');
        }
        $data = $this->validated($request, true);
        $hash = Agent::nikHash($data['nik']);
        if (Agent::withTrashed()->where('nik_hash', $hash)->exists()) {
            throw new ApiException(409, 'nik_registered', 'NIK ini sudah terdaftar sebagai agen. Satu orang hanya boleh punya satu akun agen.', errors: ['nik' => ['Sudah terdaftar.']]);
        }
        $file = FileController::claimPersonal($request, $data['ktp_file_id'], 'ktp');

        $agent = Agent::create([
            'user_id' => $user->id,
            'code' => $this->uniqueCode($user->name),
            'nik_enc' => $data['nik'],
            'nik_hash' => $hash,
            'ktp_file_id' => $file->id,
            'domicile_city' => $data['domicile_city'],
            'payout_bank_name' => $data['payout_bank_name'],
            'payout_account_no_enc' => $data['payout_account_no'],
            'payout_account_holder' => $data['payout_account_holder'],
            'status' => 'active',
        ]);
        Audit::log('agent.registered', $agent, null, ['code' => $agent->code], context: 'agent');

        return response()->json(['data' => AP::profile($agent->load('user'))], 201);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $data = $this->validated($request, false);
        if (isset($data['nik']) && Agent::nikHash($data['nik']) !== $agent->nik_hash) {
            throw new ApiException(422, 'nik_immutable', 'NIK tidak bisa diubah. Hubungi tim UMRAH CONNECT bila ada kesalahan.', errors: ['nik' => ['Tidak bisa diubah.']]);
        }
        $update = array_filter([
            'domicile_city' => $data['domicile_city'] ?? null,
            'payout_bank_name' => $data['payout_bank_name'] ?? null,
            'payout_account_no_enc' => $data['payout_account_no'] ?? null,
            'payout_account_holder' => $data['payout_account_holder'] ?? null,
        ], fn ($v) => $v !== null);
        if (isset($data['ktp_file_id'])) {
            $update['ktp_file_id'] = FileController::claimPersonal($request, $data['ktp_file_id'], 'ktp')->id;
        }
        $agent->update($update);
        Audit::log('agent.profile_updated', $agent, null, array_keys($update), context: 'agent');

        return response()->json(['data' => AP::profile($agent->fresh('user'))]);
    }

    /** PPIU terverifikasi + status kemitraan agen (FR-AGT-03). */
    public function tenants(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $q = Tenant::verified()->orderBy('brand_name')->orderBy('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);
        $parts = Partnership::withoutTenantScope()->where('agent_id', $agent->id)->get()->keyBy('tenant_id');

        return response()->json(['data' => $rows->map(fn (Tenant $t) => [
            'id' => $t->id, 'slug' => $t->slug, 'brand_name' => $t->brand_name, 'legal_name' => $t->legal_name,
            'ppiu_license_no' => $t->ppiu_license_no, 'verified' => true, 'city' => $t->city, 'province' => $t->province,
            'whatsapp' => $t->whatsapp,
            'partnership_id' => $parts[$t->id]->id ?? null,
            'partnership_status' => $parts[$t->id]->status ?? null,
            'commission_scheme' => AP::scheme(Commission::effective($t->id, $agent->id)),
        ])->values(), 'meta' => $meta]);
    }

    /** Paket terbit dari PPIU mitra aktif beserta skema komisinya (FR-AGT-05). */
    public function packages(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $request->validate(['tenant' => ['nullable', 'uuid']]);
        $tenantIds = Partnership::withoutTenantScope()->where('agent_id', $agent->id)->where('status', 'active')->pluck('tenant_id');
        $today = now('Asia/Jakarta')->toDateString();
        $q = Package::publiclyVisible()->whereIn('packages.tenant_id', $tenantIds)
            ->when($request->input('tenant'), fn ($q, $t) => $q->where('packages.tenant_id', $t))
            ->with(['tenant', 'images.file', 'departures' => fn ($d) => $d->where('status', 'scheduled')->whereDate('departure_date', '>=', $today)->orderBy('departure_date'), 'departures.prices'])
            ->orderBy('packages.name')->orderBy('packages.id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);
        $links = ReferralLink::withoutTenantScope()->where('agent_id', $agent->id)->where('is_active', true)->whereNotNull('package_id')->pluck('code', 'package_id');

        return response()->json(['data' => $rows->map(function (Package $p) use ($agent, $links) {
            $next = $p->departures->first();
            $img = $p->images->first();

            return [
                'id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'duration_days' => $p->duration_days, 'airline' => $p->airline,
                'tenant' => ['id' => $p->tenant->id, 'slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name],
                'cover_image_url' => $img ? \App\Support\CatalogPresenter::image($img)['url'] : null,
                'price_from_amount' => $p->departures->flatMap(fn ($d) => $d->prices)->min('price_amount'),
                'next_departure' => $next ? ['departure_date' => $next->departure_date->toDateString(), 'departure_city' => $next->departure_city, 'seats_remaining' => $next->seatsRemaining()] : null,
                'commission_scheme' => AP::scheme(Commission::effective($p->tenant_id, $agent->id, $p->id)),
                'referral_code' => $links[$p->id] ?? null,
            ];
        })->values(), 'meta' => $meta]);
    }

    public function links(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $rows = ReferralLink::withoutTenantScope()->where('agent_id', $agent->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(fn ($l) => AP::link($l))->values()]);
    }

    /** Link per PPIU atau per paket. Link aktif yang sama dipakai ulang (200), bukan dibuat ganda. */
    public function createLink(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $data = $request->validate(['tenant_id' => ['required', 'uuid'], 'package_id' => ['nullable', 'uuid']]);
        $part = Partnership::withoutTenantScope()->where('agent_id', $agent->id)->where('tenant_id', $data['tenant_id'])->first();
        if (! $part || $part->status !== 'active') {
            throw new ApiException(403, 'partnership_inactive', 'Link hanya bisa dibuat untuk PPIU yang kemitraannya aktif.');
        }
        if ($data['package_id'] ?? null) {
            Package::publiclyVisible()->where('packages.tenant_id', $data['tenant_id'])->whereKey($data['package_id'])->firstOr(
                fn () => throw ApiException::unprocessable('package_not_available', 'Paket tidak ditemukan atau belum terbit.')
            );
        }
        $existing = ReferralLink::withoutTenantScope()->where('agent_id', $agent->id)->where('tenant_id', $data['tenant_id'])
            ->where('package_id', $data['package_id'] ?? null)->where('is_active', true)->first();
        if ($existing) {
            return response()->json(['data' => AP::link($existing)]);
        }
        $link = ReferralLink::create([
            'tenant_id' => $data['tenant_id'], 'partnership_id' => $part->id, 'agent_id' => $agent->id,
            'package_id' => $data['package_id'] ?? null, 'code' => $this->uniqueLinkCode(),
        ]);
        Audit::log('referral_link.created', $link, null, ['code' => $link->code], tenantId: $link->tenant_id, context: 'agent');

        return response()->json(['data' => AP::link($link)], 201);
    }

    /** QR PNG 1024 px untuk dicetak/dibagikan. */
    public function qr(Request $request, string $link)
    {
        $agent = $request->attributes->get('agent');
        $l = ReferralLink::withoutTenantScope()->where('agent_id', $agent->id)->whereKey($link)->firstOrFail();
        $png = (new Writer(new GDLibRenderer(1024, 4)))->writeString(AP::link($l)['url']);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="qr-'.$l->code.'.png"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $request->merge(array_filter([
            'nik' => $request->filled('nik') ? preg_replace('/\D/', '', (string) $request->input('nik')) : null,
            'payout_account_no' => $request->filled('payout_account_no') ? preg_replace('/[\s.-]/', '', (string) $request->input('payout_account_no')) : null,
        ], fn ($v) => $v !== null));
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'nik' => [$req, 'regex:/^[0-9]{16}$/'],
            'ktp_file_id' => [$req, 'uuid'],
            'domicile_city' => [$req, 'string', 'max:100'],
            'payout_bank_name' => [$req, 'string', 'max:50'],
            'payout_account_no' => [$req, 'regex:/^[0-9]{6,20}$/'],
            'payout_account_holder' => [$req, 'string', 'max:150'],
        ], [
            'nik.regex' => 'NIK terdiri dari 16 angka sesuai KTP.',
            'payout_account_no.regex' => 'Nomor rekening hanya angka, 6–20 digit.',
        ]);
    }

    /** Kode agen 6–8 karakter: huruf nama + 2 angka, mis. BUDI24 (AgentRef.code). */
    private function uniqueCode(string $name): string
    {
        $letters = Str::upper(substr(preg_replace('/[^A-Za-z]/', '', Str::ascii($name)), 0, 4));
        $letters = str_pad($letters, 4, 'X');
        do {
            $code = $letters.random_int(10, 99);
        } while (Agent::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /** Kode link 6 karakter tanpa huruf/angka yang mirip (0/O, 1/I/L). */
    private function uniqueLinkCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (DB::table('referral_links')->where('code', $code)->exists());

        return $code;
    }
}
