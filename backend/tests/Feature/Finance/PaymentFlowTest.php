<?php

namespace Tests\Feature\Finance;

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Package;
use App\Models\Payment;
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

/** BP-06 pembayaran, BP-08 pembatalan & refund, BP-09 dokumen jamaah (exit criteria Sprint 5: alur penuh seperti prototipe). */
class PaymentFlowTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $jamaah;

    private User $owner;

    private User $finance;

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
        $this->jamaah = User::create(['name' => 'Hasan', 'email' => 'hasan@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function key(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function dep(string $date = '2027-02-10'): Departure
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();

        return Departure::withoutTenantScope()->where('package_id', $pkg->id)->where('departure_date', $date)->firstOrFail();
    }

    /** Booking 2 orang (quad 32,5 jt + double 38 jt), DP 10 jt, pelunasan 60,5 jt. */
    private function booking(?User $as = null, string $nik = '157101170575000'): array
    {
        $this->actingAs($as ?? $this->jamaah);

        return $this->key()->postJson('/api/v1/me/bookings', ['departure_id' => $this->dep()->id, 'accept_package_terms' => true, 'participants' => [
            ['room_type' => 'quad', 'pilgrim' => ['full_name' => 'Hasan', 'nik' => $nik.'1', 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L']],
            ['room_type' => 'double', 'pilgrim' => ['full_name' => 'Aisyah', 'nik' => $nik.'2', 'birth_place' => 'Jambi', 'birth_date' => '1978-05-15', 'gender' => 'P']],
        ]])->assertCreated()->json('data');
    }

    private function proof(int $amount, string $invoiceId, ?User $as = null): array
    {
        $this->actingAs($as ?? $this->jamaah);
        $file = $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('bukti.jpg')])->assertCreated()->json('data.id');

        return $this->key()->postJson("/api/v1/me/invoices/$invoiceId/payments", ['amount' => $amount, 'paid_on' => '2026-11-14', 'sender_bank' => 'BRI', 'sender_name' => 'Hasan', 'proof_file_id' => $file])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
    }

    private function url(string $p): string
    {
        return "/api/v1/tenants/{$this->a->id}$p";
    }

    public function test_bukti_dp_diverifikasi_kursi_terkonfirmasi_dan_kwitansi_terbit(): void
    {
        $b = $this->booking();
        $p = $this->proof(10000000, $b['invoices'][0]['id']);

        $this->actingAs($this->finance);
        $q = $this->getJson($this->url('/payments?filter[status]=pending'))->assertOk()->assertJsonPath('meta.counts.pending', 1)->json('data.0');
        $this->assertSame($b['code'], $q['booking']['code']);
        $this->assertNotNull($q['proof_url']);

        $res = $this->key()->postJson($this->url("/payments/{$p['id']}/verify"), ['verified_amount' => 10000000])->assertOk()
            ->assertJsonPath('data.booking.status', 'dp_verified')->assertJsonCount(1, 'data.payments');
        $this->assertMatchesRegularExpression('#^KW/PPIUA/2026/\d{4}$#', $res->json('data.payments.0.receipt.number'));
        $d = $this->dep()->fresh();
        $this->assertSame([0, 2], [$d->seats_held, $d->seats_confirmed]);
        $this->assertNull(Booking::withoutTenantScope()->find($b['id'])->hold_expires_at);

        // Diputuskan dua kali → 409
        $this->key()->postJson($this->url("/payments/{$p['id']}/verify"), ['verified_amount' => 10000000])->assertStatus(409)->assertJsonPath('error.code', 'already_processed');

        // PDF kwitansi & invoice
        $rid = $res->json('data.payments.0.receipt.id');
        $this->get($this->url("/receipts/$rid/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->jamaah);
        $this->get("/api/v1/me/receipts/$rid/pdf")->assertOk();
        $this->get("/api/v1/me/invoices/{$b['invoices'][1]['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson("/api/v1/me/bookings/{$b['id']}")->assertJsonPath('data.status', 'dp_verified')->assertJsonPath('data.payments.0.receipt.number', $res->json('data.payments.0.receipt.number'));
        // Jamaah lain tidak bisa mengunduh kwitansi ini
        $other = User::create(['name' => 'Lain', 'email' => 'lain@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($other)->get("/api/v1/me/receipts/$rid/pdf")->assertNotFound();
    }

    public function test_kelebihan_bayar_dp_dipecah_ke_pelunasan_lalu_lunas_dengan_pembayaran_tunai(): void
    {
        $b = $this->booking();
        $p = $this->proof(15000000, $b['invoices'][0]['id']);
        $this->actingAs($this->finance);
        $res = $this->key()->postJson($this->url("/payments/{$p['id']}/verify"), ['verified_amount' => 15000000])->assertOk()
            ->assertJsonCount(2, 'data.payments')->assertJsonPath('data.payments.0.verified_amount', 10000000)
            ->assertJsonPath('data.payments.1.verified_amount', 5000000)->assertJsonPath('data.payments.1.split_from_payment_id', $p['id'])
            ->assertJsonPath('data.booking.paid_amount', 15000000);
        $this->assertNotSame($res->json('data.payments.0.receipt.number'), $res->json('data.payments.1.receipt.number'));

        // Melebihi sisa total → 422
        $this->key()->postJson($this->url("/invoices/{$b['invoices'][1]['id']}/payments"), ['method' => 'cash', 'amount' => 60000000, 'paid_on' => '2026-11-14'])
            ->assertStatus(422)->assertJsonPath('error.code', 'overpayment');
        $this->key()->postJson($this->url("/invoices/{$b['invoices'][1]['id']}/payments"), ['method' => 'cash', 'amount' => 55500000, 'paid_on' => '2026-11-14', 'sender_name' => 'Hasan'])
            ->assertCreated()->assertJsonPath('data.booking.status', 'fully_paid');
        $this->actingAs($this->jamaah);
        $this->getJson("/api/v1/me/bookings/{$b['id']}")->assertJsonPath('data.invoices.1.status', 'paid')->assertJsonCount(3, 'data.payments');
    }

    public function test_bukti_ditolak_dan_bukti_menunggu_menahan_kedaluwarsa(): void
    {
        $b = $this->booking();
        $p = $this->proof(10000000, $b['invoices'][0]['id']);
        $this->travel(49)->hours();
        $this->artisan('bookings:expire-holds')->expectsOutput('0 booking kedaluwarsa.');

        $this->actingAs($this->finance);
        $this->postJson($this->url("/payments/{$p['id']}/reject"), ['reason' => 'Nominal di bukti tidak terbaca'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->artisan('bookings:expire-holds')->expectsOutput('1 booking kedaluwarsa.');
        $this->actingAs($this->jamaah);
        $this->getJson("/api/v1/me/bookings/{$b['id']}")->assertJsonPath('data.status', 'expired')->assertJsonPath('data.payments.0.reject_reason', 'Nominal di bukti tidak terbaca');
    }

    public function test_pembatalan_atas_pengajuan_refund_transfer_dan_pengalihan(): void
    {
        $b = $this->booking();
        $p = $this->proof(10000000, $b['invoices'][0]['id']);
        $this->actingAs($this->finance);
        $this->key()->postJson($this->url("/payments/{$p['id']}/verify"), ['verified_amount' => 10000000])->assertOk();

        // Jamaah mengajukan batal
        $this->actingAs($this->jamaah);
        $this->postJson("/api/v1/me/bookings/{$b['id']}/cancel-request", ['reason' => 'Ibu sakit'])->assertOk()->assertJsonPath('data.cancel_request.status', 'pending');
        $this->postJson("/api/v1/me/bookings/{$b['id']}/cancel-request", ['reason' => 'lagi'])->assertStatus(409);

        // Finance tidak boleh membatalkan; Owner boleh
        $this->actingAs($this->finance);
        $this->key()->postJson($this->url("/bookings/{$b['id']}/cancel"), ['reason' => 'x x x', 'refund_amount' => 0, 'refund_basis' => 'x x x'])->assertForbidden();
        $this->actingAs($this->owner);
        $this->key()->postJson($this->url("/bookings/{$b['id']}/cancel"), ['reason' => 'Atas pengajuan jamaah', 'refund_amount' => 12000000, 'refund_basis' => 'x x x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'refund_exceeds_paid');
        $this->key()->postJson($this->url("/bookings/{$b['id']}/cancel"), ['reason' => 'Atas pengajuan jamaah', 'refund_amount' => 7500000, 'refund_basis' => 'Batal > 45 hari: refund 75% dari DP'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_request.status', 'approved')
            ->assertJsonPath('data.refund_decided_amount', 7500000)->assertJsonPath('data.invoices.1.status', 'cancelled');
        $d = $this->dep()->fresh();
        $this->assertSame([0, 0], [$d->seats_held, $d->seats_confirmed]);

        // Refund: 5 jt ditransfer, 2,5 jt dialihkan ke booking keluarga lain, sisanya ditolak
        $other = User::create(['name' => 'Umar', 'email' => 'umar@contoh.test', 'password_hash' => 'rahasia123', 'status' => 'active', 'email_verified_at' => now()]);
        $b2 = $this->booking($other, '157101170575009');
        $this->actingAs($this->finance);
        $this->key()->postJson($this->url("/bookings/{$b['id']}/refunds"), ['type' => 'bank_transfer', 'amount' => 5000000, 'paid_on' => '2026-11-14', 'note' => 'Transfer ke BRI a.n. Hasan'])->assertCreated();
        $this->key()->postJson($this->url("/bookings/{$b['id']}/refunds"), ['type' => 'reallocation', 'amount' => 2500000, 'target_booking_id' => $b2['id'], 'paid_on' => '2026-11-14', 'note' => 'Dialihkan atas permintaan keluarga'])
            ->assertCreated()->assertJsonPath('data.target_booking_code', $b2['code']);
        $this->key()->postJson($this->url("/bookings/{$b['id']}/refunds"), ['type' => 'bank_transfer', 'amount' => 1, 'paid_on' => '2026-11-14', 'note' => 'lebih'])
            ->assertStatus(422)->assertJsonPath('error.code', 'refund_exceeds_paid');
        $t = Booking::withoutTenantScope()->find($b2['id']);
        $this->assertSame([2500000, 'awaiting_dp'], [$t->paid_amount, $t->status]);
        $this->assertSame('reallocation', Payment::withoutTenantScope()->where('status', 'verified')->whereIn('invoice_id', $t->invoices->pluck('id'))->value('method'));
        $this->getJson($this->url("/bookings/{$b['id']}/refunds"))->assertJsonCount(2, 'data');
    }

    public function test_tolak_pengajuan_batal_mencatat_catatan_internal(): void
    {
        $b = $this->booking();
        $this->postJson("/api/v1/me/bookings/{$b['id']}/cancel-request", ['reason' => 'Ragu'])->assertOk();
        $this->actingAs($this->owner);
        $this->postJson($this->url("/bookings/{$b['id']}/cancel-request/reject"), ['reason' => 'Sudah dikonfirmasi lewat telepon, jamaah tetap berangkat'])
            ->assertOk()->assertJsonPath('data.cancel_request.status', 'rejected')->assertJsonPath('data.status', 'awaiting_dp');
        $this->postJson($this->url("/bookings/{$b['id']}/notes"), ['body' => 'Minta kamar dekat lift.'])->assertCreated();
        $this->getJson($this->url("/bookings/{$b['id']}/notes"))->assertJsonCount(2, 'data')->assertJsonPath('data.0.body', 'Minta kamar dekat lift.');
    }

    public function test_dokumen_jamaah_diunggah_diganti_dan_direview_ppiu(): void
    {
        $b = $this->booking();
        $pid = $b['participants'][0]['pilgrim_id'];
        $up = function () use ($pid) {
            $f = $this->postJson('/api/v1/files', ['purpose' => 'pilgrim_document', 'file' => UploadedFile::fake()->image('paspor.jpg')])->json('data.id');

            return $this->postJson("/api/v1/me/pilgrims/$pid/documents", ['type' => 'passport', 'file_id' => $f])->assertCreated()->json('data.id');
        };
        $old = $up();
        $new = $up();
        $this->getJson("/api/v1/me/pilgrims/$pid/documents")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new);

        $this->actingAs($this->owner);
        $this->getJson($this->url("/bookings/{$b['id']}"))->assertJsonPath('data.participants.0.documents.0.id', $new);
        $this->putJson($this->url("/pilgrim-documents/$new/review"), ['status' => 'rejected'])->assertStatus(422);
        $this->putJson($this->url("/pilgrim-documents/$new/review"), ['status' => 'rejected', 'note' => 'Foto buram'])->assertOk();
        $this->getJson($this->url("/pilgrim-documents/$new/file"))->assertOk()->assertJsonStructure(['data' => ['url']]);
        $this->getJson($this->url("/pilgrims/$pid/identity"))->assertOk()->assertJsonPath('data.nik', '1571011705750001');
        $this->assertTrue(AuditLog::where('action', 'pilgrim.identity_viewed')->exists());
        $this->assertTrue(AuditLog::where('action', 'document.viewed')->exists());

        $this->actingAs($this->jamaah);
        $this->getJson("/api/v1/me/pilgrims/$pid/documents")->assertJsonPath('data.0.reviews.0.status', 'rejected')->assertJsonPath('data.0.reviews.0.tenant_name', 'PPIU A');

        // PPIU lain tidak bisa membuka dokumen jamaah yang bukan pesertanya
        $bT = Tenant::where('slug', 'ppiu-b-jambi')->first();
        $ownerB = TenantMember::withoutTenantScope()->where('tenant_id', $bT->id)->where('role', 'owner')->first()->user;
        $this->actingAs($ownerB)->getJson("/api/v1/tenants/{$bT->id}/pilgrim-documents/$new/file")->assertNotFound();
        $this->getJson("/api/v1/tenants/{$bT->id}/pilgrims/$pid/identity")->assertNotFound();
    }

    public function test_agen_mengunggah_bukti_untuk_booking_atas_namanya(): void
    {
        $budi = Agent::where('code', 'BUDI24')->first();
        $this->actingAs($budi->user);
        $b = $this->key()->postJson('/api/v1/agent/bookings', ['departure_id' => $this->dep()->id, 'accept_package_terms' => true, 'booker' => ['name' => 'Pak Rahman', 'phone' => '081299990001'],
            'participants' => [['room_type' => 'quad', 'pilgrim' => ['full_name' => 'Rahman', 'nik' => '1571011705750011', 'birth_place' => 'Jambi', 'birth_date' => '1970-01-01', 'gender' => 'L']]]])->json('data');
        $f = $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('bukti.jpg')])->json('data.id');
        $this->key()->postJson("/api/v1/agent/invoices/{$b['invoices'][0]['id']}/payments", ['amount' => 5000000, 'paid_on' => '2026-11-14', 'proof_file_id' => $f])->assertCreated();
        $this->getJson("/api/v1/agent/bookings/{$b['id']}")->assertJsonPath('data.payments.0.status', 'pending');
        $this->assertArrayNotHasKey('proof_url', $this->getJson("/api/v1/agent/bookings/{$b['id']}")->json('data.payments.0'));
        // Jamaah lain tidak bisa mengunggah ke invoice yang bukan miliknya
        $this->actingAs($this->jamaah);
        $f2 = $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('b.jpg')])->json('data.id');
        $this->key()->postJson("/api/v1/me/invoices/{$b['invoices'][0]['id']}/payments", ['amount' => 1, 'paid_on' => '2026-11-14', 'proof_file_id' => $f2])->assertNotFound();
    }
}
