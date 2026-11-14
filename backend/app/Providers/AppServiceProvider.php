<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Support\TenantContext::class);
        $this->app->bind(\App\Support\Otp\OtpSender::class, \App\Support\Otp\LogOtpSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // NFR-10 / API Documentation §1: 120 permintaan/menit per IP untuk endpoint publik.
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('referral-click', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        // Auth: per IP + identitas agar tebak kata sandi/OTP dibatasi (FR-AUTH-04).
        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(20)->by('ip:'.$r->ip()),
            Limit::perMinute(10)->by('id:'.strtolower((string) $r->input('identifier', $r->input('email', '')))),
        ]);
        RateLimiter::for('otp', fn (Request $r) => [
            Limit::perMinute(10)->by('ip:'.$r->ip()),
            Limit::perHour(10)->by('id:'.strtolower((string) $r->input('identifier'))),
        ]);
        RateLimiter::for('user', fn (Request $r) => Limit::perMinute(300)->by($r->user()?->id ?: $r->ip()));
    }
}
