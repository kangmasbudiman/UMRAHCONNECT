<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Data awal produksi: membuat akun staf platform tanpa kata sandi yang diketahui siapa pun.
 * Pemilik akun mengatur kata sandi lewat "Lupa kata sandi" (OTP ke email), lalu wajib mengaktifkan 2FA.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Super Admin} {--role=super_admin : super_admin atau platform_admin}';

    protected $description = 'Buat akun Super Admin / Admin Platform (kata sandi diatur pemilik lewat Lupa kata sandi)';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));
        $role = $this->option('role');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }
        if (! in_array($role, ['super_admin', 'platform_admin'], true)) {
            $this->error('Peran harus super_admin atau platform_admin.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error("Akun $email sudah ada. Ubah perannya lewat dasbor platform, bukan perintah ini.");

            return self::FAILURE;
        }
        $user = User::create([
            'name' => $this->option('name'), 'email' => $email, 'platform_role' => $role, 'status' => 'active',
            'password_hash' => Str::random(64), // tidak pernah ditampilkan; diganti lewat alur lupa kata sandi
            'email_verified_at' => now(),
        ]);
        Audit::log('platform_user.created', $user, null, ['email' => $email, 'platform_role' => $role], 'Dibuat lewat perintah platform:create-admin', null, 'system');

        $web = rtrim((string) config('app.web_url'), '/');
        $this->info("Akun $role dibuat untuk $email.");
        $this->line("Langkah berikut untuk pemilik akun:\n  1. Buka $web/lupa-sandi dan masukkan $email untuk mengatur kata sandi (kode OTP dikirim ke email).\n  2. Masuk, lalu aktifkan verifikasi dua langkah (2FA) di $web/akun#keamanan — wajib untuk staf platform.");

        return self::SUCCESS;
    }
}
