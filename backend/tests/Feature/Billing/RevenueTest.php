<?php

namespace Tests\Feature\Billing;

use App\Models\Agent;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\FeaturedPlacement;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PlatformInvoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\TransactionFee;
use App\Models\User;
use App\Services\BillingService;
use App\Support\Settings;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\WebSession;
use Tests\TestCase;

/** Modul pendapatan: langganan, biaya per transaksi, Paket pilihan, tagihan bulanan, telat bayar, Agen Premium. */
class RevenueTest extends TestCase
{
    use RefreshDatabase, WebSession;

    private Tenant $a;

    private User $owner;

    private User $finance;

    private User $super;

    private User $jamaah;

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
        $this->super = User::where('email', 'superadmin@umrahconnect.test')->firstOrFail();
        $this->jamaah = User::create(['name' => 'Hasan', 'email' => 'hasan@contoh.test', 'phone' => '+6281234567890', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
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

    private function bookAndPay(int $i = 1): array
    {
        $b = $this->actingAs($this->jamaah)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/me/bookings', [
            'departure_id' => $this->dep()->id, 'accept_package_terms' => true,
            'participants' => [['room_type' => 'quad', 'pilgrim' => ['full_name' => "Jamaah $i", 'nik' => '15710117057500'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'birth_place' => 'Jambi', 'birth_date' => '1975-05-17', 'gender' => 'L']]],
        ])->assertCreated()->json('data');
        $this->actingAs($this->finance);
        foreach ($b['invoices'] as $inv) {
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url("/invoices/{$inv['id']}/payments"), ['method' => 'cash', 'amount' => $inv['amount'], 'paid_on' => '2026-11-14'])->assertCreated();
        }

        return $b;
    }

    public function test_paket_gratis_dibatasi_dan_business_membuka_manifest(): void
    {
        $b = $this->bookAndPay();
        $manifest = $this->url("/departures/{$this->dep()->id}/manifest");
        $this->actingAs($this->owner)->get($manifest)->assertStatus(402)->assertJsonPath('error.code', 'plan_required');
        $this->getJson($this->url('/billing'))->assertOk()->assertJsonPath('data.plan.code', 'ppiu_free')->assertJsonPath('data.usage.max_members', 3);

        // Batas tim paket gratis: Owner + Finance + 1 lagi.
        $u1 = User::create(['name' => 'Staf Satu', 'email' => 'staf1@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $u2 = User::create(['name' => 'Staf Dua', 'email' => 'staf2@contoh.test', 'password_hash' => 'rahasia123', 'email_verified_at' => now()]);
        $this->postJson($this->url('/members/invitations'), ['email' => $u1->email, 'role' => 'admin'])->assertCreated();
        $this->postJson($this->url('/members/invitations'), ['email' => $u2->email, 'role' => 'admin'])->assertStatus(402);

        $this->actingAs($this->finance)->postJson($this->url('/billing/plan'), ['plan_code' => 'ppiu_business'])->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->url('/billing/plan'), ['plan_code' => 'ppiu_enterprise'])->assertStatus(422);
        $this->postJson($this->url('/billing/plan'), ['plan_code' => 'ppiu_business'])->assertOk()->assertJsonPath('data.plan.code', 'ppiu_business')->assertJsonPath('data.usage.max_members', 15);
        $this->postJson($this->url('/members/invitations'), ['email' => $u2->email, 'role' => 'admin'])->assertCreated();

        $csv = $this->get($manifest)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringContainsString('1571011705750001', $csv);
        $this->assertStringContainsString($b['code'], $csv);
        $rooming = $this->get($manifest.'?type=rooming')->assertOk()->getContent();
        $this->assertStringContainsString('K001', $rooming);
        $this->assertStringNotContainsString('1571011705750001', $rooming);

        // Turun ke gratis ditolak bila anggota melebihi batas.
        $this->postJson($this->url('/billing/plan'), ['plan_code' => 'ppiu_free'])->assertStatus(409)->assertJsonPath('error.code', 'members_over_limit');
    }

    public function test_tagihan_bulanan_prorata_biaya_transaksi_dan_pembayaran(): void
    {
        Settings::put('billing.transaction_fee_amount', 50000, null);
        $this->actingAs($this->owner)->postJson($this->url('/billing/plan'), ['plan_code' => 'ppiu_business'])->assertOk();
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();
        $this->postJson($this->url('/featured'), ['package_id' => $pkg->id])->assertOk()->assertJsonPath('data.featured.placements.0.status', 'active');
        $this->bookAndPay(1);
        $b2 = $this->bookAndPay(2);
        $this->assertSame(2, TransactionFee::where('status', 'accrued')->count());
        // Booking lunas yang kemudian dibatalkan sebelum ditagih: biaya dibatalkan.
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->url("/bookings/{$b2['id']}/cancel"), ['reason' => 'Jamaah sakit', 'refund_amount' => 0, 'refund_basis' => 'Kebijakan paket'])->assertOk();
        $this->assertSame('waived', TransactionFee::where('booking_id', $b2['id'])->value('status'));

        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 12, 1)->setTime(6, 0)->utc());
        $this->artisan('billing:run')->assertSuccessful();
        $inv = PlatformInvoice::with('items')->where('billable_id', $this->a->id)->firstOrFail();
        $this->assertSame(['2026-11', 'unpaid'], [$inv->period, $inv->status]);
        $this->assertMatchesRegularExpression('#^UCB/202611/\d{4}$#', $inv->number);
        $byType = $inv->items->keyBy('type');
        $this->assertSame(283000, $byType['subscription']->amount); // 499.000 × 17/30 hari, dibulatkan ke ribuan
        $this->assertSame(170000, $byType['featured']->amount);     // 300.000 × 17/30
        $this->assertSame([1, 50000], [$byType['transaction_fee']->quantity, $byType['transaction_fee']->amount]);
        $this->assertSame(503000, $inv->total);
        $this->artisan('billing:run', ['period' => '2026-11'])->assertSuccessful();
        $this->assertSame(1, PlatformInvoice::where('billable_id', $this->a->id)->count(), 'Periode yang sama tidak ditagih dua kali');
        $this->assertTrue(Notification::where('user_id', $this->owner->id)->where('event', 'billing.invoice_issued')->exists());

        // Finance mengunggah bukti → Super Admin menolak → kirim ulang → lunas.
        $this->actingAs($this->finance);
        $proof = $this->postJson('/api/v1/files', ['purpose' => 'payment_proof', 'file' => UploadedFile::fake()->image('tf.png')])->json('data.id');
        $this->postJson($this->url("/platform-invoices/{$inv->id}/confirm"), ['proof_file_id' => $proof, 'note' => 'Transfer BSI'])->assertOk()->assertJsonPath('data.invoices.0.status', 'awaiting_confirmation');
        $this->actingAs($this->super)->postJson("/api/v1/platform/invoices/{$inv->id}/reject", ['note' => 'Nominal kurang Rp3.000'])->assertOk()->assertJsonPath('data.status', 'unpaid');
        $this->actingAs($this->finance)->postJson($this->url("/platform-invoices/{$inv->id}/confirm"), ['proof_file_id' => $proof])->assertOk();
        $this->actingAs($this->super)->getJson('/api/v1/platform/invoices?status=awaiting_confirmation')->assertOk()->assertJsonPath('data.0.billable_name', 'PPIU A');
        $this->postJson("/api/v1/platform/invoices/{$inv->id}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->getJson('/api/v1/platform/revenue')->assertOk()->assertJsonPath('data.mrr', 499000 + 300000)->assertJsonPath('data.invoices.outstanding', 0);
    }

    public function test_telat_bayar_menonaktifkan_fitur_berbayar_lalu_pulih_setelah_lunas(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('/billing/plan'), ['plan_code' => 'ppiu_business'])->assertOk();
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-reguler-12-hari')->first();
        $this->postJson($this->url('/featured'), ['package_id' => $pkg->id])->assertOk();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 12, 1)->setTime(6, 0)->utc());
        $this->artisan('billing:run')->assertSuccessful();
        $inv = PlatformInvoice::where('billable_id', $this->a->id)->firstOrFail();

        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 12, 30)->setTime(7, 0)->utc()); // jatuh tempo 15 Des + tenggang 14 hari
        $this->artisan('billing:overdue')->assertSuccessful();
        $this->assertSame('past_due', Subscription::where('subscriber_id', $this->a->id)->value('status'));
        $this->assertSame('cancelled', FeaturedPlacement::where('package_id', $pkg->id)->value('status'));
        $this->actingAs($this->owner)->get($this->url("/departures/{$this->dep()->id}/manifest"))->assertStatus(402);
        $this->postJson($this->url('/featured'), ['package_id' => $pkg->id])->assertStatus(409)->assertJsonPath('error.code', 'billing_past_due');

        $this->actingAs($this->super)->postJson("/api/v1/platform/invoices/{$inv->id}/mark-paid", ['note' => 'Transfer diterima'])->assertOk();
        $this->assertSame('active', Subscription::where('subscriber_id', $this->a->id)->value('status'));
        $this->actingAs($this->owner)->get($this->url("/departures/{$this->dep()->id}/manifest"))->assertOk();
    }

    public function test_paket_pilihan_disematkan_berlabel_dan_slot_terbatas(): void
    {
        Settings::put('featured.max_slots', 1, null);
        $pkg = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('slug', 'umrah-ramadhan-15-hari')->first();
        $this->actingAs($this->owner)->postJson($this->url('/featured'), ['package_id' => $pkg->id])->assertOk();
        $this->postJson($this->url('/featured'), ['package_id' => $pkg->id])->assertStatus(409);
        $draft = Package::withoutTenantScope()->where('tenant_id', $this->a->id)->where('status', 'draft')->first();
        $this->postJson($this->url('/featured'), ['package_id' => $draft->id])->assertStatus(422);

        $b = Tenant::where('slug', 'ppiu-b-jambi')->first();
        $bOwner = TenantMember::withoutTenantScope()->where('tenant_id', $b->id)->where('role', 'owner')->first()->user;
        $bPkg = Package::withoutTenantScope()->where('tenant_id', $b->id)->first();
        $this->actingAs($bOwner)->postJson("/api/v1/tenants/{$b->id}/featured", ['package_id' => $bPkg->id])->assertStatus(409)->assertJsonPath('error.code', 'featured_slots_full');

        // Harness tes memakai instance aplikasi yang sama antarpermintaan; konteks tenant dari permintaan PPIU B direset.
        app(\App\Support\TenantContext::class)->set(null);
        $list = $this->getJson('/api/v1/public/packages')->assertOk()->json('data');
        $this->assertSame(['umrah-ramadhan-15-hari', true], [$list[0]['slug'], $list[0]['featured']]);
        $this->assertFalse($list[1]['featured']);
        $this->getJson('/api/v1/public/packages?featured=1')->assertJsonCount(1, 'data');
        // Urutan lain (mis. harga termurah) tidak disematkan.
        $this->assertNotSame('umrah-ramadhan-15-hari', $this->getJson('/api/v1/public/packages?sort=price')->json('data.0.slug'));

        $fid = FeaturedPlacement::where('package_id', $pkg->id)->value('id');
        $this->actingAs($this->owner)->postJson($this->url("/featured/$fid/end"))->assertOk();
        $this->getJson('/api/v1/public/packages?featured=1')->assertJsonCount(0, 'data');
    }

    public function test_agen_premium_membuka_halaman_agen_publik(): void
    {
        $budi = Agent::where('code', 'BUDI24')->firstOrFail();
        $this->getJson('/api/v1/public/agents/BUDI24')->assertNotFound();
        $this->actingAs($budi->user)->getJson('/api/v1/agent/billing')->assertOk()->assertJsonPath('data.plan.code', 'agent_free')->assertJsonPath('data.public_page_url', null);
        $this->postJson('/api/v1/agent/billing/plan', ['plan_code' => 'agent_premium'])->assertOk()->assertJsonPath('data.plan.code', 'agent_premium');

        $page = $this->getJson('/api/v1/public/agents/budi24')->assertOk()->json('data');
        $this->assertSame('BUDI24', $page['code']);
        $this->assertCount(2, $page['partners']);
        $this->assertNotEmpty($page['packages']);
        $this->assertTrue(collect($page['packages'])->every(fn ($p) => ! empty($p['referral_code'])), 'Setiap paket membawa kode referral agen');
        $this->assertArrayNotHasKey('nik', $page);
    }

    public function test_kendali_super_admin(): void
    {
        $padmin = User::create(['name' => 'Admin Platform', 'email' => 'padmin@contoh.test', 'password_hash' => 'rahasia123', 'platform_role' => 'platform_admin', 'email_verified_at' => now()]);
        $this->actingAs($padmin)->getJson('/api/v1/platform/revenue')->assertForbidden();

        $this->actingAs($this->super);
        $this->putJson('/api/v1/platform/plans/ppiu_business', ['price_monthly' => 599000, 'features' => ['max_members' => 20]])->assertOk()->assertJsonPath('data.features.max_members', 20)->assertJsonPath('data.features.manifest_export', true);
        $this->putJson("/api/v1/platform/tenants/{$this->a->id}/billing", ['plan_code' => 'ppiu_enterprise', 'price_override' => 2500000, 'transaction_fee_amount' => 25000])->assertOk()
            ->assertJsonPath('data.plan.code', 'ppiu_enterprise')->assertJsonPath('data.subscription.price_override', 2500000)->assertJsonPath('data.transaction_fee_amount', 25000);
        $this->bookAndPay();
        $this->assertSame(25000, TransactionFee::value('amount'));
        $this->actingAs($this->super)->postJson('/api/v1/platform/billing/run', ['period' => now('Asia/Jakarta')->format('Y-m')])->assertStatus(422);
        $this->postJson('/api/v1/platform/billing/run', ['period' => '2026-10'])->assertOk()->assertJsonPath('data.created', 0);
        $this->getJson('/api/v1/platform/revenue')->assertOk()->assertJsonPath('data.mrr', 2500000);
        $this->assertSame('PPIU Enterprise', app(BillingService::class)->plan('tenant', $this->a->id)->name);
    }
}
