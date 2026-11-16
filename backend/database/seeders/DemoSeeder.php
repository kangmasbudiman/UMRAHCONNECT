<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\BankAccount;
use App\Models\CommissionScheme;
use App\Models\Departure;
use App\Models\DeparturePrice;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\ReferralLink;
use App\Models\Tenant;
use App\Models\TenantMember;
use App\Models\User;
use App\Support\Regions;
use Illuminate\Database\Seeder;

/**
 * Data contoh untuk development dan demo (Testing Documentation §3).
 * Semua nama PPIU, nomor izin, rekening, dan NIK adalah data sintetis.
 * Kata sandi akun demo diambil dari SEED_DEMO_PASSWORD di .env.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_DEMO_PASSWORD') ?: bin2hex(random_bytes(8));

        $super = User::create(['name' => 'Super Admin', 'email' => 'superadmin@umrahconnect.test', 'password_hash' => $password, 'platform_role' => 'super_admin', 'email_verified_at' => now()]);

        // PPIU A dan B terverifikasi; C menunggu verifikasi; D ditangguhkan (tidak boleh tampil di marketplace).
        $a = $this->tenant('PPIUA', 'ppiu-a-jambi', 'PPIU A', 'PT PPIU A Jambi (data contoh)', 'U.123 Tahun 2023 (contoh)', '15.71.01.1001', 'verified', $super, [-1.6125, 103.5930]);   // Kota Jambi
        $b = $this->tenant('PPIUB', 'ppiu-b-jambi', 'PPIU B', 'PT PPIU B (data contoh)', 'U.456 Tahun 2022 (contoh)', '16.71.01.1001', 'verified', $super, [-2.9850, 104.7465]);   // Kota Palembang (contoh lintas provinsi)
        $c = $this->tenant('PPIUC', 'ppiu-c-jambi', 'PPIU C', 'PT PPIU C (data contoh)', 'U.789 Tahun 2024 (contoh)', '31.74.01.1001', 'pending_review', null, [-6.2300, 106.8575]);   // Jakarta Selatan
        $d = $this->tenant('PPIUD', 'ppiu-d-jambi', 'PPIU D', 'PT PPIU D (data contoh)', 'U.321 Tahun 2021 (contoh)', '15.05.01.1003', 'suspended', null, [-1.6420, 103.5160]);       // Kab. Muaro Jambi

        foreach ([$a, $b, $c, $d] as $t) {
            $owner = User::create(['name' => 'Owner '.$t->brand_name, 'email' => strtolower($t->code).'.owner@umrahconnect.test', 'password_hash' => $password, 'email_verified_at' => now()]);
            TenantMember::create(['tenant_id' => $t->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
            BankAccount::create(['tenant_id' => $t->id, 'bank_name' => 'Bank Syariah Indonesia', 'account_no' => '70000'.substr(crc32($t->code), 0, 5), 'account_holder' => $t->legal_name, 'status' => 'verified', 'is_default' => true, 'verified_by' => $super->id, 'verified_at' => now()]);
            CommissionScheme::create(['tenant_id' => $t->id, 'scope' => 'default', 'type' => 'flat', 'flat_amount' => 1000000]);
        }
        $finance = User::create(['name' => 'Finance PPIU A', 'email' => 'ppiua.finance@umrahconnect.test', 'password_hash' => $password, 'email_verified_at' => now()]);
        TenantMember::create(['tenant_id' => $a->id, 'user_id' => $finance->id, 'role' => 'finance', 'status' => 'active', 'joined_at' => now()]);

        $itinerary12 = [
            ['day' => 1, 'title' => 'Jambi – Jakarta – Jeddah', 'activities' => ['Berkumpul di bandara Sultan Thaha', 'Penerbangan lanjutan ke Jeddah']],
            ['day' => 2, 'title' => 'Jeddah – Madinah', 'activities' => ['Perjalanan darat ke Madinah', 'Check-in hotel']],
            ['day' => 3, 'title' => 'Madinah', 'activities' => ['Ziarah Raudhah', 'Shalat berjamaah di Masjid Nabawi']],
            ['day' => 4, 'title' => 'Madinah', 'activities' => ['Ziarah Masjid Quba, Jabal Uhud, kebun kurma']],
            ['day' => 5, 'title' => 'Madinah', 'activities' => ['Ibadah mandiri', 'Kajian bersama pembimbing']],
            ['day' => 6, 'title' => 'Madinah – Makkah', 'activities' => ['Miqat di Bir Ali', 'Umrah pertama']],
            ['day' => 7, 'title' => 'Makkah', 'activities' => ['Ibadah di Masjidil Haram']],
            ['day' => 8, 'title' => 'Makkah', 'activities' => ['Ziarah Jabal Tsur, Arafah, Mina, Muzdalifah']],
            ['day' => 9, 'title' => 'Makkah', 'activities' => ['Umrah kedua dari Ji\'ranah']],
            ['day' => 10, 'title' => 'Makkah', 'activities' => ['Ibadah mandiri']],
            ['day' => 11, 'title' => 'Makkah – Jeddah', 'activities' => ['Thawaf wada', 'Menuju bandara Jeddah']],
            ['day' => 12, 'title' => 'Tiba di Jambi', 'activities' => ['Penerbangan pulang via Jakarta']],
        ];
        $facilities = ['Tiket pesawat pulang-pergi', 'Visa umrah', 'Hotel sesuai paket', 'Makan 3 kali sehari', 'Muthawwif berbahasa Indonesia', 'Perlengkapan umrah', 'Asuransi perjalanan', 'Manasik 2 kali di Jambi'];
        $includes = ['Tiket pesawat', 'Visa', 'Akomodasi', 'Konsumsi', 'Transportasi bus AC', 'Air zamzam 5 liter'];
        $excludes = ['Pembuatan paspor', 'Vaksin meningitis', 'Kelebihan bagasi', 'Pengeluaran pribadi'];
        $cancel = 'Batal lebih dari 45 hari sebelum berangkat: refund 75%. 30–45 hari: 50%. Kurang dari 30 hari: DP hangus.';

        $reguler = $this->package($a, 'umrah-reguler-12-hari', 'Umrah Reguler 12 Hari', 12, 'Saudia', 4, 4, 450, 300, $itinerary12, $facilities, $includes, $excludes, $cancel,
            'Paket umrah reguler dengan hotel bintang 4 dekat Masjidil Haram dan Masjid Nabawi, berangkat dari Jambi.');
        $this->departure($reguler, '2027-02-10', '2027-02-21', 'Jambi', 14, 5000000, '2027-01-11', [32500000, 35000000, 38000000]);
        $this->departure($reguler, '2027-03-10', '2027-03-21', 'Jambi', 2, 5000000, '2027-02-08', [32500000, 35000000, 38000000]);

        $ramadhan = $this->package($a, 'umrah-ramadhan-15-hari', 'Umrah Ramadhan 15 Hari', 15, 'Garuda Indonesia', 5, 5, 150, 100, array_slice($itinerary12, 0, 12), $facilities, $includes, $excludes, $cancel,
            'Umrah di sepuluh hari terakhir Ramadhan dengan hotel bintang 5 di pelataran Masjidil Haram.');
        $this->departure($ramadhan, '2027-02-25', '2027-03-11', 'Jambi', 30, 10000000, '2027-01-25', [45000000, 48000000, 52000000]);

        $thaif = $this->package($b, 'umrah-plus-thaif-9-hari', 'Umrah Plus Thaif 9 Hari', 9, 'Saudia', 3, 3, 700, 500, array_slice($itinerary12, 0, 9), $facilities, $includes, $excludes, $cancel,
            'Umrah 9 hari dengan wisata religi ke kota Thaif.');
        $this->departure($thaif, '2027-01-20', '2027-01-28', 'Jambi', 40, 5000000, '2026-12-21', [29900000, 31500000, 34000000]);
        $this->departure($thaif, '2027-04-15', '2027-04-23', 'Jakarta', 40, 5000000, '2027-03-15', [29900000, 31500000, 34000000]);

        $hemat = $this->package($b, 'umrah-hemat-10-hari', 'Umrah Hemat 10 Hari', 10, 'Lion Air', 3, 3, 900, 600, array_slice($itinerary12, 0, 10), $facilities, $includes, $excludes, $cancel,
            'Pilihan hemat untuk keluarga, tetap dengan pembimbing berpengalaman.');
        $this->departure($hemat, '2027-05-05', '2027-05-14', 'Jambi', 45, 5000000, '2027-04-05', [27500000, 29000000, 31000000]);

        // Tidak boleh tampil: draf, PPIU menunggu verifikasi, PPIU ditangguhkan.
        $draft = $this->package($a, 'umrah-syawal-draf', 'Umrah Syawal (draf)', 12, 'Saudia', 4, 4, 450, 300, $itinerary12, $facilities, $includes, $excludes, $cancel, 'Draf.', 'draft');
        $this->departure($draft, '2027-04-01', '2027-04-12', 'Jambi', 20, 5000000, '2027-03-01', [33000000, 35500000, 38500000]);
        foreach ([$c, $d] as $hidden) {
            $p = $this->package($hidden, 'umrah-akhir-tahun', 'Umrah Akhir Tahun', 12, 'Saudia', 4, 4, 500, 400, $itinerary12, $facilities, $includes, $excludes, $cancel, 'Tidak boleh tampil di marketplace.');
            $this->departure($p, '2027-01-05', '2027-01-16', 'Jambi', 20, 5000000, '2026-12-05', [31000000, 33000000, 36000000]);
        }

        // Agen Budi bermitra aktif dengan PPIU A dan B; link referral Q7KX2M ke paket reguler PPIU A.
        $budiUser = User::create(['name' => 'Budi', 'phone' => '+6281200000024', 'email' => 'budi.agen@umrahconnect.test', 'password_hash' => $password, 'phone_verified_at' => now()]);
        $budi = Agent::create(['user_id' => $budiUser->id, 'code' => 'BUDI24', 'nik_enc' => '9999010101900024', 'nik_hash' => Agent::nikHash('9999010101900024'), 'domicile_city' => 'Kota Jambi', ...Regions::columns(Regions::resolveVillage('15.71.02.1002')), 'payout_bank_name' => 'BRI', 'payout_account_no_enc' => '000000000024', 'payout_account_holder' => 'Budi']);
        $pa = Partnership::create(['tenant_id' => $a->id, 'agent_id' => $budi->id, 'status' => 'active', 'source' => 'applied', 'decided_at' => now()]);
        $pb = Partnership::create(['tenant_id' => $b->id, 'agent_id' => $budi->id, 'status' => 'active', 'source' => 'invited', 'decided_at' => now()]);
        ReferralLink::create(['tenant_id' => $a->id, 'partnership_id' => $pa->id, 'agent_id' => $budi->id, 'package_id' => $reguler->id, 'code' => 'Q7KX2M', 'click_count' => 37]);
        ReferralLink::create(['tenant_id' => $b->id, 'partnership_id' => $pb->id, 'agent_id' => $budi->id, 'package_id' => null, 'code' => 'B8PPIB', 'click_count' => 5]);
    }

    private function tenant(string $code, string $slug, string $brand, string $legal, string $license, string $village, string $status, ?User $verifier = null, ?array $point = null): Tenant
    {
        $r = Regions::resolveVillage($village);

        return Tenant::create(Regions::columns($r) + [
            'code' => $code, 'slug' => $slug, 'brand_name' => $brand, 'legal_name' => $legal, 'ppiu_license_no' => $license,
            'license_expires_on' => '2028-12-31', 'city' => $r['regency_name'], 'province' => $r['province_name'],
            'address' => 'Jl. Contoh No. '.rand(1, 99).', '.$r['village_name'], 'postal_code' => null,
            'latitude' => $point[0] ?? null, 'longitude' => $point[1] ?? null,
            'phone' => '+62741000'.rand(100, 999), 'whatsapp' => '+6281200001'.rand(100, 999), 'email' => strtolower($code).'@umrahconnect.test',
            'verification_status' => $status, 'verified_at' => $verifier ? now() : null, 'verified_by' => $verifier?->id,
            'settings' => ['dp_deadline_hours_default' => 48, 'commission_earn_on_default' => 'fully_paid'],
        ]);
    }

    private function package(Tenant $t, string $slug, string $name, int $days, string $airline, int $makkahStars, int $madinahStars, int $makkahDist, int $madinahDist,
        array $itinerary, array $facilities, array $includes, array $excludes, string $cancel, string $description, string $status = 'published'): Package
    {
        return Package::create([
            'tenant_id' => $t->id, 'slug' => $slug, 'name' => $name, 'duration_days' => $days, 'airline' => $airline,
            'hotel_makkah' => 'Hotel Makkah bintang '.$makkahStars.' (contoh)', 'hotel_makkah_stars' => $makkahStars, 'hotel_makkah_distance_m' => $makkahDist,
            'hotel_madinah' => 'Hotel Madinah bintang '.$madinahStars.' (contoh)', 'hotel_madinah_stars' => $madinahStars, 'hotel_madinah_distance_m' => $madinahDist,
            'description' => $description, 'itinerary' => $itinerary, 'facilities' => $facilities, 'includes' => $includes, 'excludes' => $excludes,
            'document_requirements' => 'Paspor berlaku minimal 6 bulan, KTP, KK, pas foto latar putih, kartu vaksin meningitis.',
            'payment_terms' => 'DP dibayar paling lambat 48 jam setelah booking; pelunasan paling lambat 30 hari sebelum berangkat.',
            'cancellation_terms' => $cancel, 'status' => $status, 'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function departure(Package $p, string $date, string $return, string $city, int $quota, int $dp, string $finalDue, array $prices): Departure
    {
        $d = Departure::create([
            'tenant_id' => $p->tenant_id, 'package_id' => $p->id, 'departure_date' => $date, 'return_date' => $return, 'departure_city' => $city,
            'quota' => $quota, 'dp_min_per_pax_amount' => $dp, 'final_payment_due_on' => $finalDue,
        ]);
        foreach (['quad', 'triple', 'double'] as $i => $room) {
            DeparturePrice::create(['tenant_id' => $p->tenant_id, 'departure_id' => $d->id, 'room_type' => $room, 'price_amount' => $prices[$i]]);
        }

        return $d;
    }
}
