<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\Platform\TenantVerificationController;
use App\Http\Controllers\Api\V1\PublicCatalogController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantDocumentController;
use Illuminate\Support\Facades\Route;

/*
| UMRAH CONNECT API v1 — kontrak: api/openapi.yaml
| Base: /api/v1
*/
// Parameter ID berbentuk UUID; selain itu 404 (bukan error database).
foreach (['tenant', 'member', 'account', 'file'] as $param) {
    Route::pattern($param, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::prefix('v1')->group(function () {
    // Marketplace publik (API Documentation §4)
    Route::prefix('public')->middleware('throttle:public')->controller(PublicCatalogController::class)->group(function () {
        Route::get('packages', 'packages');
        Route::get('packages/{tenantSlug}/{packageSlug}', 'package');
        Route::get('tenants', 'tenants');
        Route::get('tenants/{tenantSlug}', 'tenant');
        Route::get('departures', 'departures');
        Route::get('facets', 'facets');
        Route::get('referrals/{code}', 'referral');
        Route::post('referrals/{code}/clicks', 'referralClick')->middleware('throttle:referral-click');
    });

    // Auth (FR-AUTH) — Sprint 1
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('register', 'register')->middleware('throttle:auth');
        Route::post('login', 'login')->middleware('throttle:auth');
        Route::post('two-factor/challenge', 'twoFactorChallenge')->middleware('throttle:auth');
        Route::post('otp/send', 'sendOtp')->middleware('throttle:otp');
        Route::post('otp/verify', 'verifyOtp')->middleware('throttle:auth');
        Route::post('password/reset', 'resetPassword')->middleware('throttle:auth');
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', 'logout');
            Route::post('two-factor/setup', 'twoFactorSetup');
            Route::post('two-factor/confirm', 'twoFactorConfirm');
        });
    });

    // File yang ditandatangani (tanpa sesi; signature relatif agar bisa lewat proxy web)
    Route::get('files/{file}/content', [FileController::class, 'content'])
        ->whereUuid('file')->middleware('signed:relative')->name('files.content');

    Route::middleware(['auth:sanctum', 'throttle:user'])->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::get('me/contexts', [MeController::class, 'contexts']);
        Route::post('me/invitations/{member}/accept', fn (\Illuminate\Http\Request $r, string $member) => app(MemberController::class)->respond($r, $member, 'accept'))->whereUuid('member');
        Route::post('me/invitations/{member}/decline', fn (\Illuminate\Http\Request $r, string $member) => app(MemberController::class)->respond($r, $member, 'decline'))->whereUuid('member');

        Route::post('files', [FileController::class, 'store']);
        Route::get('files/{file}', [FileController::class, 'show']);

        // Pendaftaran PPIU (BP-01)
        Route::post('tenants', [TenantController::class, 'store'])->middleware('contact.verified');

        // Konteks tenant: anggota aktif → 2FA (Owner/Finance) → permission
        Route::prefix('tenants/{tenant}')->middleware(['tenant', '2fa'])->group(function () {
            Route::get('/', [TenantController::class, 'show'])->middleware('perm:tenant.profile.view');
            Route::patch('/', [TenantController::class, 'update'])->middleware('perm:tenant.profile.update');
            Route::post('verification-submissions', [TenantController::class, 'submit'])->middleware('perm:owner');

            Route::get('documents', [TenantDocumentController::class, 'index'])->middleware('perm:tenant.documents.manage');
            Route::post('documents', [TenantDocumentController::class, 'store'])->middleware('perm:tenant.documents.manage');

            Route::get('bank-accounts', [BankAccountController::class, 'index'])->middleware('perm:bank_account.view');
            Route::post('bank-accounts', [BankAccountController::class, 'store'])->middleware('perm:bank_account.manage');
            Route::patch('bank-accounts/{account}', [BankAccountController::class, 'update'])->whereUuid('account')->middleware('perm:bank_account.manage');

            Route::get('members', [MemberController::class, 'index'])->middleware('perm:member.view');
            Route::post('members/invitations', [MemberController::class, 'invite'])->middleware('perm:member.manage');
            Route::patch('members/{member}', [MemberController::class, 'update'])->whereUuid('member')->middleware('perm:member.manage');
            Route::post('members/{member}/deactivate', [MemberController::class, 'deactivate'])->whereUuid('member')->middleware('perm:member.manage');
        });

        // Platform (FR-ADM) — staf platform, 2FA wajib
        Route::prefix('platform')->middleware('2fa')->controller(TenantVerificationController::class)->group(function () {
            Route::get('tenants', 'index')->middleware('platform:platform.tenant.view');
            Route::get('tenants/{tenant}', 'show')->whereUuid('tenant')->middleware('platform:platform.tenant.view');
            Route::post('tenants/{tenant}/verify', 'verify')->whereUuid('tenant')->middleware('platform:platform.tenant.verify');
            Route::post('tenants/{tenant}/suspend', 'suspend')->whereUuid('tenant')->middleware('platform:platform.tenant.suspend');
            Route::post('tenants/{tenant}/restore', 'restore')->whereUuid('tenant')->middleware('platform:platform.tenant.suspend');
            Route::post('bank-accounts/{account}/verify', 'verifyBankAccount')->whereUuid('account')->middleware('platform:platform.bank_account.verify');
        });
    });
});
