<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\TenantMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * FR-NTF: setiap kejadian penting masuk pusat notifikasi (in_app) dan, untuk yang penting, email.
 * WhatsApp Business API menyusul di Fase 2 (sementara dicatat ke log bila pengguna tidak punya email).
 * Kegagalan kirim tidak boleh menggagalkan transaksi utama.
 */
class NotificationService
{
    public const TITLES = [
        'booking.created' => 'Booking dibuat',
        'booking.created_staff' => 'Booking baru',
        'booking.attribution_changed' => 'Atribusi booking diubah',
        'tenant.ownership_received' => 'Anda menjadi Owner PPIU',
        'tenant.ownership_transferred' => 'Kepemilikan PPIU dialihkan',
        'package.taken_down' => 'Paket diturunkan tim UMRAH CONNECT',
        'package.restored' => 'Paket boleh diterbitkan lagi',
        'departure.milestone' => 'Kabar persiapan keberangkatan',
        'review.replied' => 'PPIU membalas ulasan Anda',
        'savings.reminder' => 'Pengingat tabungan umrah',
        'security.new_device' => 'Login dari perangkat baru',
        'account.deactivated' => 'Akun dinonaktifkan',
        'payment.submitted' => 'Bukti bayar baru',
        'payment.verified' => 'Pembayaran terverifikasi',
        'payment.rejected' => 'Bukti bayar ditolak',
        'booking.expired' => 'Booking kedaluwarsa',
        'booking.cancel_requested' => 'Pengajuan pembatalan',
        'booking.cancelled' => 'Booking dibatalkan',
        'partnership.applied' => 'Pengajuan kemitraan agen',
        'partnership.invited' => 'Undangan kemitraan',
        'partnership.approved' => 'Kemitraan disetujui',
        'partnership.rejected' => 'Kemitraan ditolak',
        'commission.earned' => 'Komisi diperoleh',
        'commission.approved' => 'Komisi disetujui',
        'payout.requested' => 'Pengajuan pencairan komisi',
        'payout.paid' => 'Komisi dibayarkan',
        'payout.rejected' => 'Pencairan ditolak',
        'tenant.verification_decided' => 'Hasil verifikasi PPIU',
        'invoice.due_soon' => 'Tagihan segera jatuh tempo',
        'invoice.overdue_summary' => 'Ringkasan tagihan lewat jatuh tempo',
        'departure.h14_summary' => 'Ringkasan H-14 keberangkatan',
        'license.expiring' => 'Izin PPIU segera berakhir',
        'license.expired' => 'Izin PPIU berakhir',
        'support.opened' => 'Akses dukungan dibuka',
    ];

    /** @param  array{title?:string, body:string, url?:string}  $payload */
    public function send(User $user, string $event, array $payload, ?string $tenantId = null, bool $email = false): void
    {
        try {
            $payload['title'] ??= self::TITLES[$event] ?? $event;
            Notification::create(['user_id' => $user->id, 'tenant_id' => $tenantId, 'event' => $event, 'channel' => 'in_app', 'payload' => $payload, 'status' => 'sent', 'sent_at' => now()]);
            if (! $email) {
                return;
            }
            if ($user->email) {
                $n = Notification::create(['user_id' => $user->id, 'tenant_id' => $tenantId, 'event' => $event, 'channel' => 'email', 'payload' => $payload, 'status' => 'queued']);
                // Email dikirim setelah transaksi commit: SMTP yang lambat tidak menahan kunci kursi/pembayaran,
                // dan email tidak terkirim untuk transaksi yang batal.
                DB::afterCommit(function () use ($n, $user, $payload, $event) {
                    try {
                        $link = isset($payload['url']) ? "\n\nBuka: ".rtrim(config('app.web_url'), '/').$payload['url'] : '';
                        Mail::raw($payload['body'].$link."\n\n— UMRAH CONNECT", fn ($m) => $m->to($user->email)->subject($payload['title'].' — UMRAH CONNECT'));
                        $n->update(['status' => 'sent', 'sent_at' => now()]);
                    } catch (\Throwable $e) {
                        $n->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 500)]);
                        Log::warning('Email notifikasi gagal: '.$event.' '.$e->getMessage());
                    }
                });
            } elseif ($user->phone) {
                $phone = $user->phone;
                $text = "*{$payload['title']}*\n{$payload['body']}".(isset($payload['url']) ? "\n".rtrim(config('app.web_url'), '/').$payload['url'] : '');
                DB::afterCommit(fn () => \App\Support\WhatsApp::send($phone, $text));
            }
        } catch (\Throwable $e) {
            Log::warning('Notifikasi gagal: '.$event.' '.$e->getMessage());
        }
    }

    /** Kirim ke anggota aktif PPIU dengan peran tertentu. */
    public function toTenant(string $tenantId, array $roles, string $event, array $payload, bool $email = false): void
    {
        $users = TenantMember::withoutTenantScope()->with('user')->where('tenant_id', $tenantId)->where('status', 'active')->whereIn('role', $roles)->get()->pluck('user');
        foreach ($users as $u) {
            $this->send($u, $event, $payload, $tenantId, $email);
        }
    }

    public function toPlatform(string $event, array $payload, bool $email = false): void
    {
        foreach (User::whereNotNull('platform_role')->where('status', 'active')->get() as $u) {
            $this->send($u, $event, $payload, null, $email);
        }
    }
}
