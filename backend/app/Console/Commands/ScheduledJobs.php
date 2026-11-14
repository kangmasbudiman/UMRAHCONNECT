<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Departure;
use App\Models\Invoice;
use App\Models\PilgrimDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Job terjadwal Business Process Model §JOB (MVP): JOB-02, JOB-03, JOB-05, JOB-06.
 * Semua idempoten per hari; setiap job melaporkan jumlah data yang diproses.
 */
class ScheduledJobs extends Command
{
    protected $signature = 'jobs:run {job : invoice-reminders|mark-departed|license-check|h14-summary|savings-reminders}';

    protected $description = 'Jalankan job terjadwal UMRAH CONNECT (JOB-02/03/05/06)';

    public function handle(NotificationService $notify): int
    {
        $n = match ($this->argument('job')) {
            'invoice-reminders' => $this->invoiceReminders($notify),
            'mark-departed' => $this->markDeparted(),
            'license-check' => $this->licenseCheck($notify),
            'h14-summary' => $this->h14Summary($notify),
            'savings-reminders' => $this->savingsReminders($notify),
            default => -1,
        };
        if ($n < 0) {
            $this->error('Job tidak dikenal.');

            return self::INVALID;
        }
        $this->info("{$this->argument('job')}: {$n} diproses.");

        return self::SUCCESS;
    }

    /** JOB-02: pengingat tagihan H-3 dan H-1 ke pemesan; ringkasan pelunasan lewat tempo ke PPIU. */
    private function invoiceReminders(NotificationService $notify): int
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $count = 0;
        foreach ([3, 1] as $days) {
            $day = $today->copy()->addDays($days);
            $invoices = Invoice::withoutTenantScope()->whereIn('status', ['unpaid', 'partial'])
                ->whereBetween('due_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])
                ->whereIn('booking_id', Booking::withoutTenantScope()->whereIn('status', ['awaiting_dp', 'dp_verified'])->select('id'))->get();
            foreach ($invoices as $inv) {
                $b = Booking::withoutTenantScope()->with('booker')->find($inv->booking_id);
                if ($b->booker->status === 'unclaimed') {
                    continue;
                }
                $notify->send($b->booker, 'invoice.due_soon', ['body' => "Tagihan {$inv->number} untuk booking {$b->code} sebesar Rp".number_format($inv->amount - $inv->paid_amount, 0, ',', '.')
                    ." jatuh tempo {$days} hari lagi (".$inv->due_at->setTimezone('Asia/Jakarta')->translatedFormat('j M Y').'). Transfer hanya ke rekening resmi PPIU.', 'url' => "/akun/booking/{$b->id}"], $b->tenant_id, true);
                $count++;
            }
        }
        $overdue = Invoice::withoutTenantScope()->where('type', 'final')->whereIn('status', ['unpaid', 'partial'])->where('due_at', '<', now())
            ->whereIn('booking_id', Booking::withoutTenantScope()->where('status', 'dp_verified')->select('id'))
            ->selectRaw('tenant_id, count(*) c, sum(amount - paid_amount) s')->groupBy('tenant_id')->get();
        foreach ($overdue as $row) {
            $notify->toTenant($row->tenant_id, ['owner', 'finance'], 'invoice.overdue_summary', ['body' => "{$row->c} tagihan pelunasan lewat jatuh tempo, total Rp".number_format($row->s, 0, ',', '.').'. Hubungi jamaah atau tinjau di laporan piutang.', 'url' => "/dasbor/ppiu/{$row->tenant_id}/laporan?jenis=receivables"], true);
            $count++;
        }

        return $count;
    }

    /** JOB-03: pada tanggal berangkat, keberangkatan dan booking lunas menjadi Berangkat. */
    private function markDeparted(): int
    {
        $today = now('Asia/Jakarta')->toDateString();
        $deps = Departure::withoutTenantScope()->where('status', 'scheduled')->whereDate('departure_date', '<=', $today)->get();
        foreach ($deps as $d) {
            DB::transaction(function () use ($d) {
                $d->update(['status' => 'departed', 'sales_status' => 'closed']);
                $n = Booking::withoutTenantScope()->where('departure_id', $d->id)->where('status', 'fully_paid')->update(['status' => 'departed', 'updated_at' => now()]);
                Audit::log('departure.departed', $d, ['status' => 'scheduled'], ['status' => 'departed', 'bookings_departed' => $n], null, $d->tenant_id, 'system');
            });
        }

        return $deps->count();
    }

    /** JOB-05: pengingat izin PPIU H-60/H-30; penangguhan otomatis bila izin lewat. */
    private function licenseCheck(NotificationService $notify): int
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $count = 0;
        foreach ([60, 30] as $days) {
            foreach (Tenant::verified()->whereDate('license_expires_on', $today->copy()->addDays($days)->toDateString())->get() as $t) {
                $body = "Izin PPIU {$t->brand_name} ({$t->ppiu_license_no}) berakhir {$days} hari lagi. Perbarui izin dan unggah dokumen barunya agar paket tetap tampil.";
                $notify->toTenant($t->id, ['owner'], 'license.expiring', ['body' => $body, 'url' => "/dasbor/ppiu/{$t->id}"], true);
                $notify->toPlatform('license.expiring', ['body' => $body, 'url' => "/admin/ppiu/{$t->id}"]);
                $count++;
            }
        }
        foreach (Tenant::verified()->whereDate('license_expires_on', '<', $today->toDateString())->get() as $t) {
            $t->update(['verification_status' => 'suspended', 'status_note' => 'Izin PPIU berakhir '.$t->license_expires_on->translatedFormat('j M Y').'. Ditangguhkan otomatis.']);
            Audit::log('tenant.suspended', $t, ['verification_status' => 'verified'], ['verification_status' => 'suspended'], 'Izin berakhir (JOB-05)', $t->id, 'system');
            $notify->toTenant($t->id, ['owner'], 'license.expired', ['body' => "Izin PPIU {$t->brand_name} sudah berakhir sehingga PPIU ditangguhkan dan paket tidak tampil. Hubungi tim UMRAH CONNECT setelah izin diperbarui.", 'url' => "/dasbor/ppiu/{$t->id}"], true);
            $notify->toPlatform('license.expired', ['body' => "{$t->brand_name} ditangguhkan otomatis karena izin berakhir.", 'url' => "/admin/ppiu/{$t->id}"]);
            $count++;
        }

        return $count;
    }

    /** JOB-06: ringkasan H-14 per keberangkatan ke Owner/Admin PPIU. */
    private function h14Summary(NotificationService $notify): int
    {
        $day = now('Asia/Jakarta')->addDays(14)->toDateString();
        $deps = Departure::withoutTenantScope()->with('package')->where('status', 'scheduled')->whereDate('departure_date', $day)->get();
        foreach ($deps as $d) {
            $unpaid = Booking::withoutTenantScope()->where('departure_id', $d->id)->whereIn('status', ['awaiting_dp', 'dp_verified'])->count();
            $pilgrims = BookingParticipant::withoutTenantScope()->where('departure_id', $d->id)->where('is_active', true)->pluck('pilgrim_id');
            $withPassport = PilgrimDocument::whereIn('pilgrim_id', $pilgrims)->where('type', 'passport')->where('status', 'active')->distinct()->count('pilgrim_id');
            $missing = $pilgrims->count() - $withPassport;
            $notify->toTenant($d->tenant_id, ['owner', 'admin'], 'departure.h14_summary', ['body' => "H-14 {$d->package->name} ({$d->departure_date->translatedFormat('j M Y')}): {$unpaid} booking belum lunas, {$missing} jamaah belum unggah paspor, {$d->seatsRemaining()} kursi kosong.",
                'url' => "/dasbor/ppiu/{$d->tenant_id}/booking"], true);
        }

        return $deps->count();
    }

    /** Pengingat bulanan Rencana Tabungan Umrah pada tanggal pilihan pengguna. */
    private function savingsReminders(NotificationService $notify): int
    {
        $today = now('Asia/Jakarta');
        $n = 0;
        \App\Models\SavingsPlan::with('user')->where('status', 'active')->where('reminder_day', $today->day)
            ->where('target_date', '>', $today->toDateString())->each(function ($plan) use ($notify, &$n) {
                $rp = fn ($v) => 'Rp'.number_format($v, 0, ',', '.');
                $notify->send($plan->user, 'savings.reminder', [
                    'body' => "Saatnya menyisihkan {$rp($plan->monthly_amount)} untuk \"{$plan->label}\". Terkumpul {$rp($plan->saved_amount)} dari {$rp($plan->target_amount)}.",
                    'url' => '/akun/tabungan',
                ], null, true);
                $n++;
            });

        return $n;
    }
}
