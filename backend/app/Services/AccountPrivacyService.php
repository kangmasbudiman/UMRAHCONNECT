<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\CommissionPayout;
use App\Models\Consent;
use App\Models\Notification;
use App\Models\Pilgrim;
use App\Models\PilgrimDocument;
use App\Models\Prospect;
use App\Models\Review;
use App\Models\SavingsPlan;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Hak subjek data (UU No. 27/2022 tentang Pelindungan Data Pribadi):
 * - unduh data pribadi (Pasal 7, hak akses & salinan);
 * - hapus akun (Pasal 8), dengan pengecualian data yang wajib disimpan PPIU untuk kewajiban hukum
 *   (manifes jamaah, kwitansi, invoice): data itu tetap ada tetapi tidak lagi terhubung ke identitas akun.
 */
class AccountPrivacyService
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** Konfirmasi ulang identitas: kata sandi, plus kode 2FA bila aktif. */
    public function reauthenticate(User $user, ?string $password, ?string $code): void
    {
        if (! $user->password_hash || ! Hash::check((string) $password, $user->password_hash)) {
            throw new ApiException(422, 'password_invalid', 'Kata sandi salah.', errors: ['password' => ['Kata sandi salah.']]);
        }
        if ($user->two_factor_enabled && ! $this->twoFactor->verifyUser($user, (string) $code)) {
            throw new ApiException(422, 'two_factor_invalid', 'Kode 2FA tidak cocok.', errors: ['two_factor_code' => ['Kode tidak cocok.']]);
        }
    }

    /** Seluruh data pribadi milik akun, dalam bentuk yang bisa dibaca mesin (JSON). */
    public function export(User $user): array
    {
        $agent = Agent::where('user_id', $user->id)->first();
        $pilgrims = Pilgrim::where('managed_by_user_id', $user->id)->get();
        $bookings = Booking::withoutTenantScope()->with(['tenant', 'package', 'departure', 'invoices', 'participants.pilgrim'])->where('booker_user_id', $user->id)->orderBy('created_at')->get();

        $data = [
            'generated_at' => now()->toIso8601String(),
            'note' => 'Salinan data pribadi Anda di UMRAH CONNECT (UU No. 27/2022 Pasal 7). Simpan file ini di tempat aman karena memuat NIK dan nomor paspor.',
            'account' => [
                'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'status' => $user->status,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(), 'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                'two_factor_enabled' => (bool) $user->two_factor_enabled, 'created_at' => $user->created_at?->toIso8601String(), 'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'consents' => Consent::where('user_id', $user->id)->orderBy('accepted_at')->get(['document', 'version', 'accepted_at', 'ip_address'])->toArray(),
            'pilgrims' => $pilgrims->map(fn ($p) => [
                'full_name' => $p->full_name, 'nik' => $p->nik_enc, 'birth_place' => $p->birth_place, 'birth_date' => $p->birth_date?->toDateString(),
                'gender' => $p->gender, 'passport_no' => $p->passport_no_enc, 'passport_expires_on' => $p->passport_expires_on?->toDateString(), 'phone' => $p->phone,
                'documents' => PilgrimDocument::where('pilgrim_id', $p->id)->get(['type', 'status', 'created_at'])->toArray(),
            ])->values()->all(),
            'bookings' => $bookings->map(fn ($b) => [
                'code' => $b->code, 'status' => $b->status, 'ppiu' => $b->tenant->brand_name, 'package' => $b->package->name,
                'departure_date' => $b->departure->departure_date->toDateString(), 'total_amount' => $b->total_amount, 'paid_amount' => $b->paid_amount,
                'participants' => $b->participants->map(fn ($x) => ['full_name' => $x->pilgrim->full_name, 'room_type' => $x->room_type])->values(),
                'invoices' => $b->invoices->map(fn ($i) => ['number' => $i->number, 'type' => $i->type, 'amount' => $i->amount, 'status' => $i->status])->values(),
                'created_at' => $b->created_at?->toIso8601String(),
            ])->values()->all(),
            'reviews' => Review::withoutTenantScope()->where('user_id', $user->id)->get(['rating', 'body', 'status', 'created_at'])->toArray(),
            'savings_plans' => SavingsPlan::with('entries')->where('user_id', $user->id)->get()->map(fn ($p) => [
                'label' => $p->label, 'target_amount' => $p->target_amount, 'saved_amount' => $p->saved_amount, 'target_date' => $p->target_date->toDateString(),
                'entries' => $p->entries->map(fn ($e) => ['amount' => $e->amount, 'saved_on' => $e->saved_on->toDateString(), 'note' => $e->note])->values(),
            ])->values()->all(),
            'tenant_memberships' => TenantMember::withoutTenantScope()->with('tenant')->where('user_id', $user->id)->get()
                ->map(fn ($m) => ['ppiu' => $m->tenant?->brand_name, 'role' => $m->role, 'status' => $m->status])->values()->all(),
            'notifications' => Notification::where('user_id', $user->id)->where('channel', 'in_app')->orderByDesc('created_at')->limit(500)->get(['event', 'payload', 'created_at', 'read_at'])->toArray(),
        ];
        if ($agent) {
            $data['agent'] = [
                'code' => $agent->code, 'nik' => $agent->nik_enc, 'domicile_city' => $agent->domicile_city, 'status' => $agent->status,
                'payout_bank_name' => $agent->payout_bank_name, 'payout_account_no' => $agent->payout_account_no_enc, 'payout_account_holder' => $agent->payout_account_holder,
                'commissions' => Commission::withoutTenantScope()->where('agent_id', $agent->id)->get(['amount', 'status', 'created_at'])->toArray(),
                'prospects' => Prospect::where('agent_id', $agent->id)->get(['name', 'phone', 'notes', 'follow_up_status', 'next_follow_up_on'])->toArray(),
            ];
        }
        Audit::log('user.data_exported', $user, null, null, null, null, 'pilgrim');

        return $data;
    }

    /** Alasan akun belum bisa dihapus (kosong = boleh). */
    public function blockers(User $user): array
    {
        $out = [];
        if ($user->platform_role) {
            $out[] = 'Akun staf platform tidak bisa dihapus sendiri. Minta Super Admin mencabut peran Anda terlebih dahulu.';
        }
        if (TenantMember::withoutTenantScope()->where('user_id', $user->id)->where('role', 'owner')->where('status', 'active')->exists()) {
            $out[] = 'Anda masih Owner sebuah PPIU. Alihkan kepemilikan ke anggota tim lain terlebih dahulu.';
        }
        if (Booking::withoutTenantScope()->where('booker_user_id', $user->id)->whereIn('status', ['awaiting_dp', 'dp_verified', 'fully_paid', 'departed'])->exists()) {
            $out[] = 'Anda masih punya booking yang berjalan. Akun bisa dihapus setelah perjalanan selesai atau booking dibatalkan.';
        }
        $agent = Agent::where('user_id', $user->id)->first();
        if ($agent && (Commission::withoutTenantScope()->where('agent_id', $agent->id)->whereIn('status', ['earned', 'approved'])->exists()
            || CommissionPayout::withoutTenantScope()->where('agent_id', $agent->id)->where('status', 'requested')->exists())) {
            $out[] = 'Masih ada komisi yang belum dibayar. Tunggu sampai PPIU membayarnya, atau hubungi PPIU terkait.';
        }

        return $out;
    }

    /**
     * Hapus akun: data identitas akun dihapus/dianonimkan, sesi dicabut. Data yang wajib disimpan PPIU
     * (booking selesai, invoice, kwitansi, manifes jamaah) tetap ada atas nama "Pengguna terhapus".
     */
    public function delete(User $user): void
    {
        if ($reasons = $this->blockers($user)) {
            throw new ApiException(409, 'account_not_deletable', $reasons[0], ['reasons' => $reasons]);
        }
        $email = $user->email;
        DB::transaction(function () use ($user) {
            // Jamaah tersimpan yang tidak pernah dipakai booking dihapus bersama dokumennya;
            // yang tercatat di booking tetap disimpan PPIU sebagai manifes (kewajiban hukum).
            $used = DB::table('booking_participants')->pluck('pilgrim_id')->unique();
            $unused = Pilgrim::where('managed_by_user_id', $user->id)->whereNotIn('id', $used)->pluck('id');
            DB::table('pilgrim_document_reviews')->whereIn('pilgrim_document_id', PilgrimDocument::whereIn('pilgrim_id', $unused)->pluck('id'))->delete();
            PilgrimDocument::whereIn('pilgrim_id', $unused)->delete();
            Pilgrim::whereIn('id', $unused)->update(['mahram_pilgrim_id' => null]);
            Pilgrim::whereIn('id', $unused)->delete();

            SavingsPlan::where('user_id', $user->id)->delete();
            Notification::where('user_id', $user->id)->delete();
            TenantMember::withoutTenantScope()->where('user_id', $user->id)->update(['status' => 'deactivated']);
            if ($agent = Agent::where('user_id', $user->id)->first()) {
                Prospect::where('agent_id', $agent->id)->delete();
                DB::table('referral_links')->where('agent_id', $agent->id)->update(['is_active' => false]);
                $agent->forceFill([
                    'nik_enc' => 'dihapus', 'nik_hash' => 'deleted:'.Str::uuid(), 'ktp_file_id' => null,
                    'payout_bank_name' => null, 'payout_account_no_enc' => null, 'payout_account_holder' => null, 'status' => 'suspended',
                ])->save();
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            Audit::log('user.deleted', $user, null, ['by' => 'self'], 'Permintaan penghapusan akun oleh pemilik (UU PDP Pasal 8)', null, 'pilgrim');
            $user->forceFill([
                'name' => 'Pengguna terhapus', 'email' => 'deleted-'.$user->id.'@deleted.invalid', 'phone' => null,
                'password_hash' => Str::random(64), 'email_verified_at' => null, 'phone_verified_at' => null,
                'two_factor_secret_enc' => null, 'two_factor_recovery_codes_enc' => null, 'two_factor_enabled' => false,
                'status' => 'deactivated', 'remember_token' => null,
            ])->save();
        });
        if ($email && ! str_ends_with($email, '@deleted.invalid')) {
            rescue(fn () => Mail::raw('Akun UMRAH CONNECT Anda sudah dihapus sesuai permintaan. Data transaksi yang wajib disimpan PPIU (invoice, kwitansi, manifes) tetap disimpan tanpa terhubung ke akun Anda. Bila Anda tidak meminta ini, segera hubungi kami.', fn ($m) => $m->to($email)->subject('Akun Anda sudah dihapus — UMRAH CONNECT')), report: false);
        }
    }
}
