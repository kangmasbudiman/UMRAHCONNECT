# Gate MVP — status Sprint 7 (14 Nov 2026)

Referensi: Development Roadmap §7, Testing Documentation (AC, AZ, TC-SEC, TC-PERF, UAT).

## Sudah lolos otomatis

| Kriteria | Bukti |
| --- | --- |
| AC-01–15 | Tes fitur per sprint + `tests/Feature/Security/MvpGateTest.php` (AC-11, AC-12) |
| AZ-01–18 | Sama; AZ-15 berupa pemindai route (setiap route tenant punya `perm:`, platform punya `platform:`) |
| TC-SEC-01 | `composer audit` dan `npm audit --omit=dev`: 0 kerentanan |
| TC-SEC-03 | Login ke-11 per identitas dalam 1 menit → 429 |
| TC-SEC-04/05 | Signed URL 10 menit; kolom `_enc` + blind index (tes Sprint 1–5) |
| TC-SEC-06 | Trigger database menolak UPDATE/DELETE/TRUNCATE `audit_logs` |
| TC-SEC-07 | Tipe file dari isi; PDF berisi JavaScript/aksi otomatis ditolak; gambar harus valid |
| NFR-10 header | API: nosniff, DENY, CSP ketat untuk JSON, `no-store` untuk respons login. Web: nosniff, DENY, frame-ancestors, HSTS di produksi |

Jalankan: `php artisan test` (73 tes).

## Uji beban lokal (laptop 10 core, server PHP bawaan 8 worker)

- TC-PERF-01 versi lokal (`loadtest/run-public.sh`): p95 endpoint publik 62–87 ms pada 20 koneksi, 333 ms pada 100 koneksi; 0 gagal. Server dev menolak 200 koneksi serentak (batas antrean socket), jadi 200 VU **harus diulang di staging** (nginx + php-fpm).
- TC-PERF-02 versi lokal (`php artisan test --testsuite=Load`): 50 booking serentak pada kuota 14 → tepat 14 berhasil, 36 ditolak 409, tidak ada overbooking. p95 ±730–800 ms karena 50 proses berebut CPU laptop; target 500 ms diukur di staging.

## Belum bisa diselesaikan di kode — butuh tim/staging

1. UAT-01–08 dengan 2 PPIU calon pilot (22 Mar–2 Apr 2027), tanda tangan persetujuan.
2. TC-SEC-02 OWASP ZAP baseline dan TC-SEC-08 uji penetrasi eksternal di staging.
3. TC-PERF-01/02/03/05 di staging dengan data sintetis 500 PPIU / 50.000 jamaah; TC-PERF-04 Lighthouse.
4. NFR-07/08: uptime monitoring, backup harian + uji restore (bagian dokumen Deployment).
5. Pemindaian antivirus file unggahan (ClamAV) — pemeriksaan PDF saat ini berbasis pola teks dan tidak membuka stream terkompresi.

## Data awal produksi

```bash
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force   # pengaturan platform saja, tanpa data contoh
php artisan platform:create-admin admin@domain-anda --name="Nama Admin"
php artisan commissions:backfill                       # sekali, aman diulang
php artisan app:preflight                              # wajib lolos sebelum go-live
```

`DatabaseSeeder` tidak pernah memanggil `DemoSeeder` bila `APP_ENV=production`. Super Admin mengatur kata sandinya sendiri lewat "Lupa kata sandi", lalu wajib mengaktifkan 2FA.

## Catatan keputusan

- AZ-01 menyebut 403; API Documentation menetapkan 404 untuk data di luar lingkup. Diterapkan 404 (agar keberadaan data tenant lain tidak bocor) **dan** percobaannya dicatat di audit log sebagai `tenant.access_denied`.
- Permission ubah atribusi memakai nama dari matriks peran (`booking.attribution.change`); `openapi.yaml` disesuaikan.
