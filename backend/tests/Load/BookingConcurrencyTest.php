<?php

namespace Tests\Load;

use App\Models\Booking;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/**
 * TC-PERF-02 (versi lokal): 50 proses memesan bersamaan pada keberangkatan berkuota 14.
 * Lolos bila tidak ada kursi melebihi kuota dan yang gagal mendapat 409. Batas p95 lokal: LOAD_P95_MS (bawaan 1000 ms);
 * target resmi 500 ms (NFR-09) diukur di staging. Jumlah proses: LOAD_WORKERS (bawaan 50).
 * Tidak ikut `php artisan test` biasa; jalankan: php artisan test --testsuite=Load
 */
class BookingConcurrencyTest extends TestCase
{
    use DatabaseMigrations, WebSession;

    private int $workers = 50;

    public function test_booking_serentak_tidak_melebihi_kuota(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Butuh ekstensi pcntl.');
        }
        $this->workers = (int) (getenv('LOAD_WORKERS') ?: 50);
        $this->setUpWebSession();
        $this->seed(DemoSeeder::class);
        config(['permissions.enforce_two_factor' => false]);
        $a = Tenant::where('slug', 'ppiu-a-jambi')->firstOrFail();
        $pkg = Package::withoutTenantScope()->where('tenant_id', $a->id)->where('slug', 'umrah-reguler-12-hari')->firstOrFail();
        $dep = Departure::withoutTenantScope()->where('package_id', $pkg->id)->where('departure_date', '2027-02-10')->firstOrFail();
        $users = [];
        for ($i = 0; $i < $this->workers; $i++) {
            $users[] = User::create(['name' => "Beban $i", 'email' => "beban$i@contoh.test", 'password_hash' => 'rahasia123', 'email_verified_at' => now()])->id;
        }
        $dir = sys_get_temp_dir().'/uc-load-'.Str::random(6);
        mkdir($dir);
        DB::disconnect();

        $pids = [];
        foreach ($users as $i => $uid) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::reconnect();
                usleep(random_int(0, 20000));
                $t = microtime(true);
                $res = $this->actingAs(User::find($uid))->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/bookings', [
                    'departure_id' => $dep->id, 'accept_package_terms' => true,
                    'participants' => [['room_type' => 'quad', 'pilgrim' => ['full_name' => "Jamaah $i", 'nik' => '15710117057'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L']]],
                ]);
                file_put_contents("$dir/$i.json", json_encode(['status' => $res->status(), 'ms' => (microtime(true) - $t) * 1000, 'code' => $res->json('error.code')]));
                posix_kill(getmypid(), SIGKILL); // keluar tanpa menjalankan teardown PHPUnit di proses anak
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        $results = array_map(fn ($f) => json_decode(file_get_contents($f), true), glob("$dir/*.json"));
        array_map('unlink', glob("$dir/*.json"));
        rmdir($dir);
        $this->assertCount($this->workers, $results, 'Ada proses yang tidak melapor');
        $ok = array_filter($results, fn ($r) => $r['status'] === 201);
        $conflicts = array_filter($results, fn ($r) => $r['status'] === 409);
        $ms = array_column($results, 'ms');
        sort($ms);
        $p95 = $ms[(int) ceil(0.95 * count($ms)) - 1];

        $dep->refresh();
        $held = Booking::withoutTenantScope()->where('departure_id', $dep->id)->whereIn('status', ['awaiting_dp', 'dp_verified', 'fully_paid'])->sum('participant_count');
        fwrite(STDERR, sprintf("\nTC-PERF-02: %d berhasil, %d ditolak 409, lainnya %d; p50=%.0fms p95=%.0fms max=%.0fms; kursi terpakai %d/%d\n",
            count($ok), count($conflicts), count($results) - count($ok) - count($conflicts), $ms[intdiv(count($ms), 2)], $p95, end($ms), $dep->seats_held + $dep->seats_confirmed, $dep->quota));

        $expected = min($this->workers, $dep->quota);
        $this->assertSame($expected, count($ok), 'Jumlah booking berhasil harus sama dengan kuota');
        $this->assertSame($this->workers - $expected, count($conflicts), 'Sisanya harus 409 kursi habis');
        $this->assertSame($expected, (int) $held);
        $this->assertSame($expected, $dep->seats_held + $dep->seats_confirmed);
        // Laptop dev: 50 proses dingin ditembak serentak ke satu keberangkatan → antre CPU. Target resmi 500 ms (NFR-09)
        // diukur di staging; lokal dipakai batas LOAD_P95_MS (bawaan 1000 ms) agar regresi besar tetap tertangkap.
        $limit = (int) (getenv('LOAD_P95_MS') ?: 1000);
        $this->assertLessThanOrEqual($limit, $p95, "p95 booking melebihi {$limit} ms");
    }
}
