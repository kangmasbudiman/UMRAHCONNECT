<?php

namespace Tests\Feature\Finance;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Departure;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/** Sprint 6 (exit criteria: agen menerima komisi; Finance mengekspor laporan) + notifikasi, job, audit, akses dukungan. */
class CommissionReportTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $owner;

    private User $finance;

    private Agent $budi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWebSession();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 14)->setTime(10, 0)->utc());
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        config(['permissions.enforce_two_factor' => false]);
        $this->a = Tenant::where('slug', 'ppiu-a-jambi')->firstOrFail();
        $this->owner = TenantMember::withoutTenantScope()->where('tenant_id', $this->a->id)->where('role', 'owner')->first()->user;
        $this->finance = User::where('email', 'ppiua.finance@umrahconnect.test')->firstOrFail();
        $this->budi = Agent::where('code', 'BUDI24')->firstOrFail();
    }

    private function key(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function url(string $p): string
    {
        return "/api/v1/tenants/{$this->a->id}$p";
    }

    private function dep(): Departure
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();

        return Departure::withoutTenantScope()->where('package_id', $pkg->id)->where('departure_date', '2027-02-10')->firstOrFail();
    }

    /** Booking lewat agen Budi (skema default flat Rp1.000.000/jamaah, diperoleh saat lunas). */
    private function agentBooking(int $pax = 2): array
    {
        $this->actingAs($this->budi->user);
        $participants = [];
        for ($i = 1; $i <= $pax; $i++) {
            $participants[] = ['room_type' => 'quad', 'pilgrim' => ['full_name' => "Jamaah $i", 'nik' => '15710117057500'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L']];
        }

        return $this->key()->postJson('/api/v1/agent/bookings', ['departure_id' => $this->dep()->id, 'accept_package_terms' => true,
            'booker' => ['name' => 'Pak Rahman', 'phone' => '081299990001'], 'participants' => $participants])->assertCreated()->json('data');
    }

    private function payAll(array $b): void
    {
        $this->actingAs($this->finance);
        foreach ($b['invoices'] as $inv) {
            $this->key()->postJson($this->url("/invoices/{$inv['id']}/payments"), ['method' => 'cash', 'amount' => $inv['amount'], 'paid_on' => '2026-11-14'])->assertCreated();
        }
    }

    public function test_agen_menerima_komisi_dari_perkiraan_sampai_dibayar(): void
    {
        $b = $this->agentBooking();
        $c = Commission::withoutTenantScope()->where('booking_id', $b['id'])->firstOrFail();
        $this->assertSame(['estimated', 2000000], [$c->status, $c->amount]);

        // DP saja belum memenuhi earn_on=fully_paid
        $this->actingAs($this->finance);
        $this->key()->postJson($this->url("/invoices/{$b['invoices'][0]['id']}/payments"), ['method' => 'cash', 'amount' => $b['invoices'][0]['amount'], 'paid_on' => '2026-11-14'])->assertCreated();
        $this->assertSame('estimated', $c->fresh()->status);
        $this->key()->postJson($this->url("/invoices/{$b['invoices'][1]['id']}/payments"), ['method' => 'cash', 'amount' => $b['invoices'][1]['amount'], 'paid_on' => '2026-11-14'])
            ->assertJsonPath('data.booking.status', 'fully_paid');
        $this->assertSame('earned', $c->fresh()->status);
        $this->assertTrue(Notification::where('user_id', $this->budi->user_id)->where('event', 'commission.earned')->where('channel', 'in_app')->exists());

        // Finance menyetujui
        $this->getJson($this->url('/commissions?filter[status]=earned'))->assertOk()->assertJsonPath('meta.totals.earned.amount', 2000000);
        $this->postJson($this->url('/commissions/approve'), ['ids' => [$c->id]])->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->postJson($this->url('/commissions/approve'), ['ids' => [$c->id]])->assertStatus(409);

        // Agen mengajukan pencairan
        $this->actingAs($this->budi->user);
        $this->getJson('/api/v1/agent/commissions')->assertOk()->assertJsonPath('meta.totals.approved.amount', 2000000);
        $pid = $this->key()->postJson('/api/v1/agent/payout-requests', ['tenant_id' => $this->a->id, 'ids' => [$c->id]])->assertCreated()
            ->assertJsonPath('data.total_amount', 2000000)->json('data.id');
        $this->key()->postJson('/api/v1/agent/payout-requests', ['tenant_id' => $this->a->id, 'ids' => [$c->id]])->assertStatus(409);

        // Finance membayar dengan bukti
        $this->actingAs($this->finance);
        $proof = $this->postJson('/api/v1/files', ['purpose' => 'payout_proof', 'file' => UploadedFile::fake()->image('tf.jpg')])->json('data.id');
        $this->key()->postJson($this->url("/payouts/$pid/pay"), ['paid_at' => now()->toIso8601String(), 'proof_file_id' => $proof])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('paid', $c->fresh()->status);
        $this->actingAs($this->budi->user);
        $this->getJson('/api/v1/agent/payout-requests')->assertJsonPath('data.0.status', 'paid');
        $this->getJson('/api/v1/me/notifications?unread=1')->assertOk()->assertJsonPath('data.0.event', 'payout.paid');
        $this->postJson('/api/v1/me/notifications/read', [])->assertNoContent();
        $this->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread', 0);
    }

    public function test_komisi_batal_saat_booking_batal_dan_payout_ditolak_mengembalikan_status(): void
    {
        $b = $this->agentBooking(1);
        $this->actingAs($this->owner);
        $this->key()->postJson($this->url("/bookings/{$b['id']}/cancel"), ['reason' => 'Batal', 'refund_amount' => 0, 'refund_basis' => 'Belum ada pembayaran'])->assertOk();
        $this->assertSame('cancelled', Commission::withoutTenantScope()->where('booking_id', $b['id'])->value('status'));

        $b2 = $this->agentBooking(1);
        $this->payAll($b2);
        $c = Commission::withoutTenantScope()->where('booking_id', $b2['id'])->first();
        $this->postJson($this->url('/commissions/approve'), ['ids' => [$c->id]])->assertOk();
        $pid = $this->key()->postJson($this->url('/payouts'), ['agent_id' => $this->budi->id, 'ids' => [$c->id]])->assertCreated()->json('data.id');
        $this->postJson($this->url("/payouts/$pid/reject"), ['reason' => 'Rekening agen tidak valid'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $c->refresh();
        $this->assertSame(['approved', null], [$c->status, $c->payout_id]);
    }

    public function test_laporan_penjualan_dan_keuangan_serta_ekspor_csv(): void
    {
        $b = $this->agentBooking(2);
        $this->payAll($b);
        $this->actingAs($this->finance);
        $sales = $this->getJson($this->url('/reports/sales?group_by=agent&from=2026-11-01&to=2026-11-30'))->assertOk()->json('data');
        $this->assertSame('Budi (BUDI24)', $sales['rows'][0]['name']);
        $this->assertSame(65000000, $sales['totals']['sales_amount']);
        $this->getJson($this->url('/reports/finance?type=receipts&from=2026-11-01&to=2026-11-30'))->assertOk()->assertJsonCount(2, 'data.rows')->assertJsonPath('data.totals.amount', 65000000);
        $this->getJson($this->url('/reports/finance?type=commissions_due'))->assertOk()->assertJsonPath('data.rows.0.earned', 2000000);

        $csv = $this->get($this->url('/reports/finance/export?format=csv&type=receipts&from=2026-11-01&to=2026-11-30'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('"No. kwitansi";Booking', $csv);
        $this->assertStringContainsString('TOTAL', $csv);
        $this->assertTrue(AuditLog::where('action', 'report.exported')->exists());

        // Admin PPIU: penjualan boleh, keuangan tidak
        $admin = User::create(['name' => 'Admin A', 'email' => 'admin@a.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        TenantMember::create(['tenant_id' => $this->a->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin);
        $this->get($this->url('/reports/sales/export?format=csv&group_by=package&from=2026-11-01&to=2026-11-30'))->assertOk();
        $this->getJson($this->url('/reports/finance/export?format=csv&type=receipts'))->assertForbidden();
        $this->getJson($this->url('/reports/finance?type=receipts'))->assertForbidden();
        $this->getJson($this->url('/dashboard'))->assertOk()->assertJsonPath('data.bookings_by_status.fully_paid', 1)->assertJsonPath('data.commissions_to_approve.amount', 2000000);
    }

    public function test_job_terjadwal_pengingat_berangkat_izin_dan_h14(): void
    {
        $b = $this->agentBooking(1);
        // JOB-02: DP jatuh tempo 16 Nov → H-1 bila dijalankan 15 Nov; pemesan unclaimed dilewati, gunakan pemesan berakun
        $u = User::create(['name' => 'Hasan', 'email' => 'hasan@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        Booking::withoutTenantScope()->whereKey($b['id'])->update(['booker_user_id' => $u->id]);
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 11, 15)->setTime(8, 0)->utc());
        $this->artisan('jobs:run invoice-reminders')->expectsOutput('invoice-reminders: 1 diproses.');
        $this->assertTrue(Notification::where('user_id', $u->id)->where('event', 'invoice.due_soon')->exists());

        // JOB-05: izin PPIU A dipercepat → lewat → ditangguhkan otomatis
        $this->a->update(['license_expires_on' => '2026-11-14']);
        $this->artisan('jobs:run license-check')->expectsOutput('license-check: 1 diproses.');
        $this->assertSame('suspended', $this->a->fresh()->verification_status);

        // JOB-06 dan JOB-03
        $this->travelTo(now('Asia/Jakarta')->setDate(2027, 1, 27)->setTime(7, 30)->utc());
        $this->artisan('jobs:run h14-summary')->expectsOutput('h14-summary: 1 diproses.');
        $this->assertTrue(Notification::where('event', 'departure.h14_summary')->where('user_id', $this->owner->id)->exists());
        $this->travelTo(now('Asia/Jakarta')->setDate(2027, 2, 10)->setTime(0, 5)->utc());
        Booking::withoutTenantScope()->whereKey($b['id'])->update(['status' => 'fully_paid', 'paid_amount' => 32500000]);
        $this->artisan('jobs:run mark-departed');
        $this->assertSame('departed', $this->dep()->fresh()->status);
        $this->assertSame('departed', Booking::withoutTenantScope()->find($b['id'])->status);
    }

    public function test_dasbor_platform_audit_log_dan_akses_dukungan_baca_saja(): void
    {
        $super = User::where('email', 'superadmin@umrahconnect.test')->first();
        $ops = User::create(['name' => 'Ops', 'email' => 'ops@umrahconnect.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'platform_role' => 'platform_admin', 'email_verified_at' => now()]);
        $this->agentBooking(1);

        $this->actingAs($super);
        $this->getJson('/api/v1/platform/dashboard')->assertOk()->assertJsonPath('data.bookings_by_status.awaiting_dp', 1);
        $this->getJson('/api/v1/platform/audit-logs?filter[action]=booking.')->assertOk()->assertJsonPath('data.0.action', 'booking.created');

        // Admin Platform: tanpa sesi → 404; dengan sesi → GET boleh, POST ditolak; sesi berakhir → ditolak
        $this->actingAs($ops);
        $this->getJson($this->url('/bookings'))->assertNotFound();
        $sid = $this->postJson('/api/v1/platform/support-sessions', ['tenant_id' => $this->a->id, 'reason' => 'Jamaah melapor invoice tidak muncul', 'ticket_no' => 'CS-0012'])
            ->assertCreated()->assertJsonPath('data.active', true)->json('data.id');
        $this->assertTrue(Notification::where('user_id', $this->owner->id)->where('event', 'support.opened')->exists());
        $this->withHeader('X-Support-Session', $sid)->getJson($this->url('/bookings'))->assertOk()->assertJsonCount(1, 'data');
        $this->withHeader('X-Support-Session', $sid)->postJson($this->url('/bookings/x/notes'), ['body' => 'x'])->assertNotFound();
        $this->withHeader('X-Support-Session', $sid)->postJson($this->url('/commissions/approve'), ['ids' => []])->assertForbidden()->assertJsonPath('error.code', 'support_read_only');
        $this->assertTrue(AuditLog::where('action', 'support.viewed')->exists());
        $this->travel(61)->minutes();
        $this->withHeader('X-Support-Session', $sid)->getJson($this->url('/bookings'))->assertForbidden()->assertJsonPath('error.code', 'support_session_expired');
        // Admin Platform hanya melihat log tenant
        $this->flushHeaders();
        $this->withHeader('Referer', 'http://localhost:3000/');
        $logs = $this->getJson('/api/v1/platform/audit-logs?limit=200')->assertOk()->json('data');
        $this->assertNotContains(null, array_column($logs, 'tenant_id'));
    }
}
