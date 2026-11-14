<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Services\ReportService;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Laporan, ekspor, dan dasbor PPIU (FR-PPIU-15/16, FR-FIN-10). */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function sales(Request $request, Tenant $tenant): JsonResponse
    {
        $d = $request->validate(['group_by' => ['required', 'in:package,departure,agent'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->reports->sales($tenant->id, $d['group_by'], $d['from'], $d['to'])]);
    }

    public function finance(Request $request, Tenant $tenant): JsonResponse
    {
        $d = $request->validate(['type' => ['required', 'in:receipts,receivables,commissions_due,refunds'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->reports->finance($tenant->id, $d['type'], $d['from'] ?? null, $d['to'] ?? null)]);
    }

    /** Ekspor CSV langsung (MVP; antrean xlsx menyusul). Admin hanya laporan penjualan. */
    public function export(Request $request, Tenant $tenant, string $report)
    {
        abort_unless(in_array($report, ['sales', 'finance'], true), 404);
        $member = $request->attributes->get('membership');
        if (! Permissions::tenantRoleAllows($member->role, "report.export:{$report}")) {
            throw new AuthorizationException;
        }
        $request->validate(['format' => ['required', 'in:csv']], ['format.in' => 'Format yang tersedia saat ini: csv.']);
        if ($report === 'sales') {
            $d = $request->validate(['group_by' => ['required', 'in:package,departure,agent'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
            $data = $this->reports->sales($tenant->id, $d['group_by'], $d['from'], $d['to']);
            $name = "penjualan-{$d['group_by']}-{$d['from']}-{$d['to']}.csv";
        } else {
            $d = $request->validate(['type' => ['required', 'in:receipts,receivables,commissions_due,refunds'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
            $data = $this->reports->finance($tenant->id, $d['type'], $d['from'] ?? null, $d['to'] ?? null);
            $name = "keuangan-{$d['type']}-".($d['from'] ?? 'awal').'-'.($d['to'] ?? 'kini').'.csv';
        }
        Audit::log('report.exported', $tenant, null, ['report' => $report] + $d, null, $tenant->id, 'tenant');

        return ReportService::csv($data, $name);
    }

    /** PPIU-04 Dasbor: booking, kursi, pembayaran & komisi menunggu, refund tertunda. */
    public function dashboard(Tenant $tenant): JsonResponse
    {
        $today = now('Asia/Jakarta')->toDateString();
        $byStatus = Booking::where('tenant_id', $tenant->id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $pending = Payment::where('tenant_id', $tenant->id)->where('status', 'pending')->whereNull('split_from_payment_id');
        $deps = Departure::where('tenant_id', $tenant->id)->where('status', 'scheduled')->whereDate('departure_date', '>=', $today)
            ->with('package')->orderBy('departure_date')->limit(6)->get();
        $refundPending = Booking::where('tenant_id', $tenant->id)->where('status', 'cancelled')->whereNotNull('refund_decided_amount')->get()
            ->map(fn ($b) => $b->refund_decided_amount - (int) Refund::where('booking_id', $b->id)->sum('amount'))->filter(fn ($x) => $x > 0);
        $monthStart = now('Asia/Jakarta')->startOfMonth()->utc();

        return response()->json(['data' => [
            'bookings_by_status' => $byStatus,
            'bookings_this_month' => Booking::where('tenant_id', $tenant->id)->where('created_at', '>=', $monthStart)->whereNotIn('status', ['expired', 'cancelled'])->count(),
            'sales_this_month' => (int) Booking::where('tenant_id', $tenant->id)->where('created_at', '>=', $monthStart)->whereNotIn('status', ['expired', 'cancelled'])->sum('total_amount'),
            'received_this_month' => (int) Payment::where('tenant_id', $tenant->id)->where('status', 'verified')->where('verified_at', '>=', $monthStart)->sum('verified_amount'),
            'pending_payments' => ['count' => (clone $pending)->count(), 'amount' => (int) (clone $pending)->sum('claimed_amount')],
            'cancel_requests' => Booking::where('tenant_id', $tenant->id)->where('cancel_request_status', 'pending')->count(),
            'commissions_to_approve' => ['count' => Commission::where('tenant_id', $tenant->id)->where('status', 'earned')->count(), 'amount' => (int) Commission::where('tenant_id', $tenant->id)->where('status', 'earned')->sum('amount')],
            'payout_requests' => \App\Models\CommissionPayout::where('tenant_id', $tenant->id)->where('status', 'requested')->count(),
            'refunds_pending' => ['count' => $refundPending->count(), 'amount' => (int) $refundPending->sum()],
            'upcoming_departures' => $deps->map(fn ($d) => [
                'id' => $d->id, 'package_name' => $d->package->name, 'departure_date' => $d->departure_date->toDateString(), 'departure_city' => $d->departure_city,
                'quota' => $d->quota, 'seats_held' => $d->seats_held, 'seats_confirmed' => $d->seats_confirmed, 'seats_remaining' => $d->seatsRemaining(), 'sales_status' => $d->sales_status,
            ])->values(),
        ]]);
    }
}
