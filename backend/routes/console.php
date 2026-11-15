<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// JOB-01: lepas kursi booking yang tenggat DP-nya lewat (Business Process Model §JOB).
Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();

// JOB-02/03/05/06 (Business Process Model §JOB), jadwal WIB.
Schedule::command('jobs:run invoice-reminders')->dailyAt('08:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('jobs:run mark-departed')->dailyAt('00:05')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('jobs:run license-check')->dailyAt('07:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('jobs:run h14-summary')->dailyAt('07:30')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('jobs:run savings-reminders')->dailyAt('07:15')->timezone('Asia/Jakarta')->withoutOverlapping();

// Sekali jalan saat rilis Sprint 6: buat komisi "perkiraan" untuk booking beragen yang dibuat sebelum modul komisi aktif.
Artisan::command('commissions:backfill', function (App\Services\CommissionService $svc) {
    $n = 0;
    App\Models\Booking::withoutTenantScope()->whereNotNull('agent_id')->whereNotNull('commission_snapshot')
        ->whereNotIn('id', App\Models\Commission::withoutTenantScope()->select('booking_id'))->each(function ($b) use ($svc, &$n) {
            $c = $svc->estimate($b);
            if (in_array($b->status, ['expired', 'cancelled'], true)) {
                $c?->update(['status' => 'cancelled', 'cancelled_at' => now()]);
            } else {
                $svc->onPaymentProgress($b);
            }
            $n++;
        });
    $this->info("{$n} komisi dibuat.");
})->purpose('Buat komisi untuk booking lama yang belum punya komisi');

// NFR-07/08: detak penjadwal (dibaca /api/v1/health & app:preflight), backup harian, uji restore mingguan.
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever('scheduler:heartbeat', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
Schedule::command('app:backup')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('app:backup-verify')->weeklyOn(0, '03:00')->timezone('Asia/Jakarta')->withoutOverlapping();

// Pendapatan platform: tagihan bulan lalu setiap tanggal 1 pukul 06.00 WIB; penegakan telat bayar setiap hari.
Artisan::command('billing:run {period? : YYYY-MM, bawaan bulan lalu}', function (App\Services\BillingService $billing) {
    $period = $this->argument('period') ?? now('Asia/Jakarta')->subMonth()->format('Y-m');
    $this->info("Periode {$period}: ".$billing->runPeriod($period).' tagihan dibuat.');
})->purpose('Buat tagihan platform (langganan, Paket pilihan, biaya per transaksi) untuk satu periode');
Artisan::command('billing:overdue', function (App\Services\BillingService $billing) {
    $this->info($billing->enforceOverdue().' langganan dipindah ke past_due.');
})->purpose('Nonaktifkan fitur berbayar bagi tagihan yang lewat masa tenggang');
Schedule::command('billing:run')->monthlyOn(1, '06:00')->timezone('Asia/Jakarta')->withoutOverlapping();
Schedule::command('billing:overdue')->dailyAt('06:30')->timezone('Asia/Jakarta')->withoutOverlapping();
