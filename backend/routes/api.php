<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CommissionController;
use App\Http\Controllers\Api\V1\CommissionSchemeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Platform\PlatformOpsController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\PartnershipController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingOpsController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PilgrimDocumentController;
use App\Http\Controllers\Api\V1\PilgrimController;
use App\Http\Controllers\Api\V1\DepartureController;
use App\Http\Controllers\Api\V1\PackageController;
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
foreach (['tenant', 'member', 'account', 'file', 'package', 'departure', 'image', 'partnership', 'scheme', 'link', 'pilgrim', 'booking', 'invoice', 'payment', 'receipt', 'document', 'payout', 'session'] as $param) {
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
        Route::get('config', 'config');
        Route::get('packages/{tenantSlug}/{packageSlug}/reviews', [\App\Http\Controllers\Api\V1\ReviewController::class, 'publicIndex']);
        Route::get('track', [\App\Http\Controllers\Api\V1\MilestoneController::class, 'track'])->middleware('throttle:referral-click');
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
        Route::get('me/notifications', [NotificationController::class, 'index']);
        Route::get('me/savings-plans', [\App\Http\Controllers\Api\V1\SavingsController::class, 'index']);
        Route::post('me/savings-plans', [\App\Http\Controllers\Api\V1\SavingsController::class, 'store']);
        Route::patch('me/savings-plans/{plan}', [\App\Http\Controllers\Api\V1\SavingsController::class, 'update'])->whereUuid('plan');
        Route::delete('me/savings-plans/{plan}', [\App\Http\Controllers\Api\V1\SavingsController::class, 'destroy'])->whereUuid('plan');
        Route::post('me/savings-plans/{plan}/entries', [\App\Http\Controllers\Api\V1\SavingsController::class, 'addEntry'])->whereUuid('plan');
        Route::post('me/notifications/read', [NotificationController::class, 'read']);
        Route::post('me/invitations/{member}/accept', fn (\Illuminate\Http\Request $r, string $member) => app(MemberController::class)->respond($r, $member, 'accept'))->whereUuid('member');
        Route::post('me/invitations/{member}/decline', fn (\Illuminate\Http\Request $r, string $member) => app(MemberController::class)->respond($r, $member, 'decline'))->whereUuid('member');

        // Data jamaah & booking milik pengguna (Sprint 4, FR-JMH)
        Route::get('me/pilgrims', [PilgrimController::class, 'index']);
        Route::post('me/pilgrims', [PilgrimController::class, 'store']);
        Route::get('me/pilgrims/{pilgrim}', [PilgrimController::class, 'show']);
        Route::patch('me/pilgrims/{pilgrim}', [PilgrimController::class, 'update']);
        Route::delete('me/pilgrims/{pilgrim}', [PilgrimController::class, 'destroy']);
        Route::get('me/bookings', [BookingController::class, 'myIndex']);
        Route::get('me/bookings/{booking}', [BookingController::class, 'myShow']);
        Route::post('me/bookings', [BookingController::class, 'myStore'])->middleware(['contact.verified', 'idempotent']);
        // Sprint 5: bukti bayar, kwitansi, pembatalan, dokumen jamaah
        Route::post('me/invoices/{invoice}/payments', [PaymentController::class, 'myUpload'])->middleware('idempotent');
        Route::get('me/invoices/{invoice}/pdf', [PaymentController::class, 'myInvoicePdf']);
        Route::get('me/receipts/{receipt}/pdf', [PaymentController::class, 'myReceiptPdf']);
        Route::post('me/bookings/{booking}/cancel-request', [BookingOpsController::class, 'myCancelRequest']);
        Route::post('me/bookings/{booking}/review', [\App\Http\Controllers\Api\V1\ReviewController::class, 'store'])->whereUuid('booking');
        Route::get('me/pilgrims/{pilgrim}/documents', [PilgrimDocumentController::class, 'index']);
        Route::post('me/pilgrims/{pilgrim}/documents', [PilgrimDocumentController::class, 'store']);

        Route::post('files', [FileController::class, 'store']);
        Route::get('files/{file}', [FileController::class, 'show']);

        // Agen (Sprint 3, BP-03/BP-04)
        Route::prefix('agent')->group(function () {
            Route::get('profile', [AgentController::class, 'showProfile']);
            Route::post('profile', [AgentController::class, 'register'])->middleware('contact.verified');
            Route::middleware('agent')->group(function () {
                Route::patch('profile', [AgentController::class, 'updateProfile']);
                Route::get('tenants', [AgentController::class, 'tenants']);
                Route::get('packages', [AgentController::class, 'packages']);
                Route::get('referral-links', [AgentController::class, 'links']);
                Route::post('referral-links', [AgentController::class, 'createLink']);
                Route::get('referral-links/{link}/qr', [AgentController::class, 'qr']);
                Route::get('partnerships', [PartnershipController::class, 'agentIndex']);
                Route::post('partnerships', [PartnershipController::class, 'apply']);
                Route::post('partnerships/{partnership}/accept', [PartnershipController::class, 'accept']);
                Route::post('partnerships/{partnership}/decline', [PartnershipController::class, 'decline']);
                Route::post('partnerships/{partnership}/end', [PartnershipController::class, 'agentEnd']);
                Route::get('bookings', [BookingController::class, 'agentIndex']);
                Route::get('bookings/{booking}', [BookingController::class, 'agentShow']);
                Route::post('bookings', [BookingController::class, 'agentStore'])->middleware('idempotent');
                Route::post('invoices/{invoice}/payments', [PaymentController::class, 'agentUpload'])->middleware('idempotent');
                Route::get('commissions', [CommissionController::class, 'agentIndex']);
                Route::get('payout-requests', [CommissionController::class, 'agentPayouts']);
                Route::post('payout-requests', [CommissionController::class, 'agentRequestPayout'])->middleware('idempotent');
                Route::get('prospects', [\App\Http\Controllers\Api\V1\ProspectController::class, 'index']);
                Route::post('prospects', [\App\Http\Controllers\Api\V1\ProspectController::class, 'store']);
                Route::patch('prospects/{prospect}', [\App\Http\Controllers\Api\V1\ProspectController::class, 'update'])->whereUuid('prospect');
                Route::delete('prospects/{prospect}', [\App\Http\Controllers\Api\V1\ProspectController::class, 'destroy'])->whereUuid('prospect');
            });
        });

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
            Route::post('ownership-transfer', [MemberController::class, 'transferOwnership'])->middleware(['perm:ownership.transfer', 'throttle:reauth']);

            // Booking PPIU (Sprint 4, FR-PPIU-12/13)
            Route::get('bookings', [BookingController::class, 'tenantIndex'])->middleware('perm:booking.view');
            Route::post('bookings', [BookingController::class, 'tenantStore'])->middleware(['perm:booking.create', 'idempotent']);
            Route::get('bookings/{booking}', [BookingController::class, 'tenantShow'])->middleware('perm:booking.view');

            // Keuangan & operasional booking (Sprint 5, BP-06/08/09)
            Route::get('payments', [PaymentController::class, 'tenantIndex'])->middleware('perm:payment.view');
            Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->middleware(['perm:payment.verify', 'idempotent']);
            Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->middleware('perm:payment.verify');
            Route::post('invoices/{invoice}/payments', [PaymentController::class, 'record'])->middleware(['perm:payment.record', 'idempotent']);
            Route::get('invoices/{invoice}/pdf', [PaymentController::class, 'invoicePdf'])->middleware('perm:invoice.view');
            Route::get('receipts/{receipt}/pdf', [PaymentController::class, 'receiptPdf'])->middleware('perm:invoice.view');
            Route::post('bookings/{booking}/cancel', [BookingOpsController::class, 'cancel'])->middleware(['perm:booking.cancel', 'idempotent']);
            Route::patch('bookings/{booking}/attribution', [BookingOpsController::class, 'changeAttribution'])->middleware('perm:booking.attribution.change');
            Route::get('reviews', [\App\Http\Controllers\Api\V1\ReviewController::class, 'tenantIndex'])->middleware('perm:package.view');
            Route::post('reviews/{review}/reply', [\App\Http\Controllers\Api\V1\ReviewController::class, 'reply'])->whereUuid('review')->middleware('perm:package.manage');
            Route::post('bookings/{booking}/cancel-request/reject', [BookingOpsController::class, 'rejectCancelRequest'])->middleware('perm:booking.cancel');
            Route::get('bookings/{booking}/refunds', [BookingOpsController::class, 'refunds'])->middleware('perm:payment.view');
            Route::post('bookings/{booking}/refunds', [BookingOpsController::class, 'recordRefund'])->middleware(['perm:refund.record', 'idempotent']);
            Route::get('bookings/{booking}/notes', [BookingOpsController::class, 'notes'])->middleware('perm:booking.note');
            Route::post('bookings/{booking}/notes', [BookingOpsController::class, 'addNote'])->middleware('perm:booking.note');
            Route::get('pilgrims/{pilgrim}/identity', [BookingOpsController::class, 'identity'])->middleware('perm:pilgrim.identity.view');
            Route::get('pilgrim-documents/{document}/file', [BookingOpsController::class, 'documentFile'])->middleware('perm:pilgrim.identity.view');
            Route::put('pilgrim-documents/{document}/review', [BookingOpsController::class, 'reviewDocument'])->middleware('perm:pilgrim_document.review');

            // Komisi, payout, laporan, dasbor (Sprint 6, BP-07)
            Route::get('commissions', [CommissionController::class, 'tenantIndex'])->middleware('perm:commission.view');
            Route::post('commissions/approve', [CommissionController::class, 'approve'])->middleware('perm:commission.approve');
            Route::get('payouts', [CommissionController::class, 'payouts'])->middleware('perm:commission.view');
            Route::post('payouts', [CommissionController::class, 'createPayout'])->middleware(['perm:payout.pay', 'idempotent']);
            Route::post('payouts/{payout}/pay', [CommissionController::class, 'pay'])->middleware(['perm:payout.pay', 'idempotent']);
            Route::post('payouts/{payout}/reject', [CommissionController::class, 'reject'])->middleware('perm:payout.pay');
            Route::get('reports/sales', [ReportController::class, 'sales'])->middleware('perm:report.sales.view');
            Route::get('reports/finance', [ReportController::class, 'finance'])->middleware('perm:report.finance.view');
            Route::get('reports/sales/export', [ReportController::class, 'export'])->defaults('report', 'sales')->middleware('perm:report.export:sales');
            Route::get('reports/finance/export', [ReportController::class, 'export'])->defaults('report', 'finance')->middleware('perm:report.export:finance');
            Route::get('dashboard', [ReportController::class, 'dashboard'])->middleware('perm:report.sales.view');

            // Kemitraan agen & skema komisi (Sprint 3, BP-03)
            Route::controller(PartnershipController::class)->group(function () {
                Route::get('partnerships', 'tenantIndex')->middleware('perm:partnership.view');
                Route::post('partnerships/invitations', 'invite')->middleware('perm:partnership.manage');
                Route::post('partnerships/{partnership}/approve', 'approve')->middleware('perm:partnership.manage');
                Route::post('partnerships/{partnership}/reject', 'reject')->middleware('perm:partnership.manage');
                Route::post('partnerships/{partnership}/suspend', 'suspend')->middleware('perm:partnership.manage');
                Route::post('partnerships/{partnership}/reactivate', 'reactivate')->middleware('perm:partnership.manage');
                Route::post('partnerships/{partnership}/end', 'tenantEnd')->middleware('perm:partnership.manage');
            });
            Route::get('commission-schemes', [CommissionSchemeController::class, 'index'])->middleware('perm:commission_scheme.view');
            Route::post('commission-schemes', [CommissionSchemeController::class, 'store'])->middleware('perm:commission_scheme.manage');
            Route::post('commission-schemes/{scheme}/end', [CommissionSchemeController::class, 'end'])->middleware('perm:commission_scheme.manage');

            // Katalog paket (Sprint 2, BP-02)
            Route::controller(PackageController::class)->group(function () {
                Route::get('packages', 'index')->middleware('perm:package.view');
                Route::post('packages', 'store')->middleware('perm:package.manage');
                Route::get('packages/{package}', 'show')->middleware('perm:package.view');
                Route::patch('packages/{package}', 'update')->middleware('perm:package.manage');
                Route::post('packages/{package}/duplicate', 'duplicate')->middleware('perm:package.manage');
                Route::post('packages/{package}/archive', 'archive')->middleware('perm:package.manage');
                Route::post('packages/{package}/publish', 'publish')->middleware('perm:package.publish');
                Route::post('packages/{package}/unpublish', 'unpublish')->middleware('perm:package.publish');
                Route::post('packages/{package}/images', 'addImage')->middleware('perm:package.manage');
                Route::put('packages/{package}/images/order', 'reorderImages')->middleware('perm:package.manage');
                Route::delete('packages/{package}/images/{image}', 'deleteImage')->middleware('perm:package.manage');
            });
            Route::controller(DepartureController::class)->group(function () {
                Route::get('packages/{package}/departures', 'index')->middleware('perm:package.view');
                Route::post('packages/{package}/departures', 'store')->middleware('perm:departure.manage');
                Route::get('departures/{departure}', 'show')->middleware('perm:package.view');
                Route::patch('departures/{departure}', 'update')->middleware('perm:departure.manage');
                Route::put('departures/{departure}/prices', 'replacePrices')->middleware('perm:departure.price.update');
                Route::get('departures/{departure}/milestones', [\App\Http\Controllers\Api\V1\MilestoneController::class, 'index'])->middleware('perm:package.view');
                Route::put('departures/{departure}/milestones/{type}', [\App\Http\Controllers\Api\V1\MilestoneController::class, 'update'])->middleware('perm:departure.manage');
                Route::post('departures/{departure}/close-sales', 'closeSales')->middleware('perm:departure.manage');
                Route::post('departures/{departure}/open-sales', 'openSales')->middleware('perm:departure.manage');
                Route::post('departures/{departure}/cancel', 'cancel')->middleware('perm:departure.manage');
                Route::post('departures/{departure}/complete', 'complete')->middleware('perm:departure.manage');
            });
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
        Route::prefix('platform')->middleware('2fa')->controller(\App\Http\Controllers\Api\V1\Platform\PlatformAdminController::class)->group(function () {
            Route::get('packages', 'packages')->middleware('platform:platform.tenant.view');
            Route::post('packages/{package}/take-down', 'takeDown')->whereUuid('package')->middleware('platform:platform.package.take_down');
            Route::post('packages/{package}/restore', 'restorePackage')->whereUuid('package')->middleware('platform:platform.package.take_down');
            Route::get('reviews', [\App\Http\Controllers\Api\V1\ReviewController::class, 'platformIndex'])->middleware('platform:platform.tenant.view');
            Route::post('reviews/{review}/hide', [\App\Http\Controllers\Api\V1\ReviewController::class, 'hide'])->whereUuid('review')->middleware('platform:platform.package.take_down');
            Route::post('reviews/{review}/unhide', [\App\Http\Controllers\Api\V1\ReviewController::class, 'unhide'])->whereUuid('review')->middleware('platform:platform.package.take_down');
            Route::get('users', 'users')->middleware('platform:platform.user.view');
            Route::post('users/{user}/deactivate', 'deactivateUser')->whereUuid('user')->middleware('platform:platform.user.manage:limited');
            Route::post('users/{user}/reactivate', 'reactivateUser')->whereUuid('user')->middleware('platform:platform.user.manage:limited');
            Route::get('admins', 'admins')->middleware('platform:platform.admin.manage');
            Route::post('admins', 'addAdmin')->middleware('platform:platform.admin.manage');
            Route::delete('admins/{user}', 'removeAdmin')->whereUuid('user')->middleware('platform:platform.admin.manage');
            Route::get('settings', 'settings')->middleware('platform:platform.settings.manage');
            Route::get('settings/{key}', 'setting')->middleware('platform:platform.settings.manage');
            Route::put('settings/{key}', 'putSetting')->middleware('platform:platform.settings.manage');
        });
        Route::prefix('platform')->middleware('2fa')->controller(PlatformOpsController::class)->group(function () {
            Route::get('dashboard', 'dashboard')->middleware('platform:platform.dashboard.view');
            Route::get('audit-logs', 'auditLogs')->middleware('platform:platform.audit_log.view:tenant');
            Route::get('support-sessions', 'supportSessions')->middleware('platform:platform.support_access');
            Route::post('support-sessions', 'openSupport')->middleware('platform:platform.support_access');
            Route::delete('support-sessions/{session}', 'endSupport')->middleware('platform:platform.support_access');
        });
    });
});
