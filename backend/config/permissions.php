<?php

/*
| Matriks permission — User Role & Permission v1.1 §6 (config/permissions.php).
| Tidak ada bypass Gate::before untuk Super Admin: semua dicek lewat daftar ini.
| Pola 'x.*' cocok dengan semua permission berawalan 'x.'.
*/
return [
    // Katalog permission konteks tenant. Owner = semua isi katalog (bukan permission platform).
    'tenant_catalog' => [
        'tenant.profile.view', 'tenant.profile.update', 'tenant.documents.manage', 'tenant.settings.update',
        'member.view', 'member.manage', 'ownership.transfer',
        'bank_account.view', 'bank_account.manage',
        'package.view', 'package.manage', 'package.publish',
        'departure.manage', 'departure.price.update',
        'partnership.view', 'partnership.manage', 'commission_scheme.view', 'commission_scheme.manage',
        'booking.view', 'booking.create', 'booking.cancel', 'booking.note', 'booking.attribution.change',
        'pilgrim.identity.view', 'pilgrim_document.review',
        'invoice.view', 'payment.view', 'payment.verify', 'payment.record',
        'commission.view', 'commission.approve', 'payout.pay', 'refund.record',
        'report.sales.view', 'report.finance.view', 'report.export:sales', 'report.export:finance',
    ],

    'tenant' => [
        'owner' => ['*'],
        'admin' => ['tenant.profile.view', 'member.view', 'package.*', 'departure.*',
            'partnership.view', 'commission_scheme.view', 'booking.view',
            'booking.create', 'booking.cancel', 'booking.note', 'pilgrim.identity.view',
            'pilgrim_document.review', 'invoice.view', 'payment.view',
            'commission.view', 'report.sales.view', 'report.export:sales'],
        'finance' => ['tenant.profile.view', 'member.view', 'bank_account.view', 'package.view',
            'partnership.view', 'commission_scheme.view', 'booking.view',
            'invoice.view', 'payment.*', 'commission.*', 'payout.pay', 'refund.record', 'booking.note',
            'report.*'],
        // Staf platform lewat akses dukungan (baca-saja, 60 menit, X-Support-Session).
        'support' => ['tenant.profile.view', 'member.view', 'bank_account.view', 'package.view', 'partnership.view', 'commission_scheme.view',
            'booking.view', 'invoice.view', 'payment.view', 'commission.view', 'report.sales.view', 'report.finance.view'],
    ],

    'platform' => [
        'super_admin' => ['platform.*'],
        'platform_admin' => ['platform.dashboard.view', 'platform.tenant.view',
            'platform.tenant.verify', 'platform.bank_account.verify',
            'platform.package.take_down', 'platform.user.view',
            'platform.user.manage:limited', 'platform.support_access',
            'platform.audit_log.view:tenant'],
    ],

    // Peran yang wajib 2FA (User Role §3). Penegakan bisa dimatikan di lokal lewat AUTH_ENFORCE_2FA=false.
    'two_factor_required' => [
        'platform' => ['super_admin', 'platform_admin'],
        'tenant' => ['owner', 'finance'],
    ],
    'enforce_two_factor' => env('AUTH_ENFORCE_2FA', true),
];
