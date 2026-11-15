<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Booking;
use App\Models\FeaturedPlacement;
use App\Models\Package;
use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\PlatformInvoiceItem;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TransactionFee;
use App\Models\User;
use App\Support\Audit;
use App\Support\DocumentNumber;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pendapatan platform (BRD): langganan PPIU/agen, biaya teknologi per booking lunas, promosi paket.
 * Model pascabayar: setiap tanggal 1 ditagih pemakaian bulan sebelumnya (prorata per hari).
 * Tagihan hanya ke PPIU/agen; jamaah tidak pernah dikenai biaya platform.
 */
class BillingService
{
    public const DUE_DAYS = 14;

    public const GRACE_DAYS_AFTER_DUE = 14;

    public function __construct(private NotificationService $notify) {}

    // ---------------- Paket & hak fitur ----------------

    public function subscription(string $type, string $id): ?Subscription
    {
        return Subscription::with('plan')->where('subscriber_type', $type)->where('subscriber_id', $id)->whereIn('status', ['active', 'past_due'])->first();
    }

    /** Paket yang berlaku saat ini (past_due = kembali ke paket gratis). */
    public function plan(string $type, string $id): Plan
    {
        $sub = $this->subscription($type, $id);

        return $sub && $sub->status === 'active' ? $sub->plan : Plan::findOrFail($type === 'tenant' ? 'ppiu_free' : 'agent_free');
    }

    public function feature(string $type, string $id, string $key): mixed
    {
        return $this->plan($type, $id)->features[$key] ?? null;
    }

    public function requireFeature(string $type, string $id, string $key, string $message): void
    {
        if (! $this->feature($type, $id, $key)) {
            throw new ApiException(402, 'plan_required', $message);
        }
    }

    /** Ganti paket. Pindah ke paket gratis = mengakhiri langganan berbayar (ditagih prorata sampai hari ini). */
    public function changePlan(string $type, string $id, string $planCode, User $actor, ?int $priceOverride = null, bool $byPlatform = false): Plan
    {
        $plan = Plan::findOrFail($planCode);
        if ($plan->audience !== $type || (! $plan->is_public && ! $byPlatform)) {
            throw new ApiException(422, 'plan_invalid', 'Paket ini tidak tersedia.', errors: ['plan_code' => ['Tidak tersedia.']]);
        }
        $current = $this->subscription($type, $id);
        if ($current && $current->status === 'past_due' && ! $byPlatform) {
            throw new ApiException(409, 'billing_past_due', 'Ada tagihan platform yang lewat jatuh tempo. Lunasi dulu sebelum mengganti paket.');
        }
        DB::transaction(function () use ($current, $plan, $type, $id, $actor, $priceOverride) {
            if ($current) {
                $current->update(['status' => 'cancelled', 'ended_at' => now()]);
            }
            if ($plan->price_monthly > 0 || $priceOverride !== null || ! str_ends_with($plan->code, '_free')) {
                Subscription::create(['subscriber_type' => $type, 'subscriber_id' => $id, 'plan_code' => $plan->code, 'status' => 'active',
                    'price_override' => $priceOverride, 'started_at' => now(), 'created_by' => $actor->id]);
            }
        });
        Audit::log('billing.plan_changed', $this->entity($type, $id), ['plan' => $current?->plan_code], ['plan' => $plan->code, 'price_override' => $priceOverride],
            null, $type === 'tenant' ? $id : null, $byPlatform ? 'platform' : ($type === 'tenant' ? 'tenant' : 'agent'));

        return $plan;
    }

    // ---------------- Biaya per transaksi ----------------

    /** Dipanggil saat booking menjadi lunas. Tarif kontrak per PPIU (tenants.settings) atau tarif bawaan platform. */
    public function accrueTransactionFee(Booking $b): void
    {
        $tenant = Tenant::find($b->tenant_id);
        $amount = (int) ($tenant->settings['transaction_fee_amount'] ?? Settings::get('billing.transaction_fee_amount'));
        if ($amount <= 0) {
            return;
        }
        TransactionFee::query()->insertOrIgnore([['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $b->tenant_id, 'booking_id' => $b->id, 'amount' => $amount, 'status' => 'accrued', 'accrued_at' => now()]]);
    }

    /** Booking batal sebelum ditagih: biaya dibatalkan. */
    public function waiveTransactionFee(Booking $b): void
    {
        TransactionFee::where('booking_id', $b->id)->where('status', 'accrued')->update(['status' => 'waived']);
    }

    // ---------------- Promosi paket ----------------

    public function startFeatured(Tenant $tenant, string $packageId, User $actor): FeaturedPlacement
    {
        $pkg = Package::withoutTenantScope()->where('tenant_id', $tenant->id)->whereKey($packageId)->first()
            ?? throw new ApiException(422, 'validation_failed', 'Paket tidak ditemukan.', errors: ['package_id' => ['Tidak ditemukan.']]);
        if ($pkg->status !== 'published' || $tenant->verification_status !== 'verified') {
            throw new ApiException(422, 'package_not_published', 'Hanya paket yang sedang terbit yang bisa dipromosikan.');
        }
        if ($this->subscription('tenant', $tenant->id)?->status === 'past_due') {
            throw new ApiException(409, 'billing_past_due', 'Ada tagihan platform yang lewat jatuh tempo. Lunasi dulu sebelum memasang promosi.');
        }
        return DB::transaction(function () use ($tenant, $pkg, $actor) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['featured-slots']);
            if (FeaturedPlacement::where('package_id', $pkg->id)->where('status', 'active')->exists()) {
                throw ApiException::conflict('already_featured', 'Paket ini sudah dipromosikan.');
            }
            $max = (int) Settings::get('featured.max_slots');
            if (FeaturedPlacement::where('status', 'active')->count() >= $max) {
                throw ApiException::conflict('featured_slots_full', "Slot Paket pilihan sedang penuh ({$max} slot). Coba lagi nanti.");
            }
            $f = FeaturedPlacement::create(['tenant_id' => $tenant->id, 'package_id' => $pkg->id, 'status' => 'active',
                'price_monthly' => (int) Settings::get('featured.price_monthly'), 'starts_at' => now(), 'created_by' => $actor->id]);
            Audit::log('billing.featured_started', $f, null, ['package' => $pkg->name, 'price_monthly' => $f->price_monthly], tenantId: $tenant->id, context: 'tenant');

            return $f;
        });
    }

    public function endFeatured(FeaturedPlacement $f, string $status = 'ended'): void
    {
        if ($f->status === 'active') {
            $f->update(['status' => $status, 'ends_at' => now()]);
            Audit::log('billing.featured_ended', $f, ['status' => 'active'], ['status' => $status], tenantId: $f->tenant_id, context: 'tenant');
        }
    }

    /** ID paket yang sedang dipromosikan (paket terbit dari PPIU terverifikasi). */
    public static function featuredPackageIds(): array
    {
        return FeaturedPlacement::where('status', 'active')->pluck('package_id')->all();
    }

    // ---------------- Tagihan bulanan ----------------

    /** Buat tagihan untuk periode YYYY-MM (bulan yang sudah lewat). Aman diulang: periode yang sudah ditagih dilewati. */
    public function runPeriod(string $period): int
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$period}-01 00:00:00", 'Asia/Jakarta');
        $end = $start->copy()->addMonth();
        $days = $start->daysInMonth;
        $prorate = function (int $monthly, Carbon $from, ?Carbon $to) use ($start, $end, $days): array {
            $a = $from->greaterThan($start) ? $from : $start;
            $b = $to && $to->lessThan($end) ? $to : $end;
            $d = max(0, (int) ceil($a->diffInSeconds($b) / 86400));
            $d = min($d, $days);

            return [$d, (int) (round($monthly * $d / $days / 1000) * 1000)];
        };

        $lines = []; // key "type:id" => items[]
        Subscription::with('plan')->where('started_at', '<', $end)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))
            ->get()->each(function (Subscription $s) use (&$lines, $prorate, $days) {
                $price = $s->monthlyPrice();
                if ($price <= 0) {
                    return;
                }
                [$d, $amt] = $prorate($price, $s->started_at->copy()->setTimezone('Asia/Jakarta'), $s->ended_at?->copy()->setTimezone('Asia/Jakarta'));
                if ($amt > 0) {
                    $lines["{$s->subscriber_type}:{$s->subscriber_id}"][] = ['type' => 'subscription', 'description' => "Langganan {$s->plan->name} ({$d}/{$days} hari)", 'quantity' => 1, 'unit_amount' => $amt, 'amount' => $amt, 'ref_id' => $s->id];
                }
            });
        FeaturedPlacement::with('package')->where('starts_at', '<', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start))
            ->get()->each(function (FeaturedPlacement $f) use (&$lines, $prorate, $days) {
                [$d, $amt] = $prorate($f->price_monthly, $f->starts_at->copy()->setTimezone('Asia/Jakarta'), $f->ends_at?->copy()->setTimezone('Asia/Jakarta'));
                if ($amt > 0) {
                    $lines["tenant:{$f->tenant_id}"][] = ['type' => 'featured', 'description' => "Paket pilihan: {$f->package->name} ({$d}/{$days} hari)", 'quantity' => 1, 'unit_amount' => $amt, 'amount' => $amt, 'ref_id' => $f->id];
                }
            });
        $fees = TransactionFee::where('status', 'accrued')->where('accrued_at', '<', $end)->get()->groupBy('tenant_id');
        foreach ($fees as $tenantId => $rows) {
            foreach ($rows->groupBy('amount') as $unit => $group) {
                $lines["tenant:{$tenantId}"][] = ['type' => 'transaction_fee', 'description' => 'Biaya teknologi per booking lunas', 'quantity' => $group->count(), 'unit_amount' => (int) $unit, 'amount' => (int) $unit * $group->count(), 'ref_id' => null, '_fee_ids' => $group->pluck('id')->all()];
            }
        }

        $created = 0;
        foreach ($lines as $key => $items) {
            [$type, $id] = explode(':', $key);
            if (PlatformInvoice::where('billable_type', $type)->where('billable_id', $id)->where('period', $period)->exists()) {
                continue;
            }
            $invoice = DB::transaction(function () use ($type, $id, $period, $items) {
                $inv = PlatformInvoice::create([
                    'number' => sprintf('UCB/%s/%04d', str_replace('-', '', $period), DocumentNumber::next(null, 'platform_invoice', str_replace('-', '', $period))),
                    'billable_type' => $type, 'billable_id' => $id, 'period' => $period, 'status' => 'unpaid',
                    'total' => array_sum(array_column($items, 'amount')), 'due_on' => now('Asia/Jakarta')->addDays(self::DUE_DAYS)->toDateString(), 'issued_at' => now(),
                ]);
                foreach ($items as $it) {
                    $feeIds = $it['_fee_ids'] ?? [];
                    unset($it['_fee_ids']);
                    PlatformInvoiceItem::create($it + ['invoice_id' => $inv->id]);
                    if ($feeIds) {
                        TransactionFee::whereIn('id', $feeIds)->update(['status' => 'invoiced', 'invoice_id' => $inv->id]);
                    }
                }

                return $inv;
            });
            $created++;
            $this->notifyBillable($invoice, 'billing.invoice_issued', "Tagihan platform {$invoice->number} periode {$period}: Rp".number_format($invoice->total, 0, ',', '.').', jatuh tempo '.$invoice->due_on->translatedFormat('j F Y').'.');
        }

        return $created;
    }

    /** Tagihan lewat jatuh tempo + masa tenggang: langganan berbayar jadi past_due (fitur kembali gratis), promosi berhenti. */
    public function enforceOverdue(): int
    {
        $cutoff = now('Asia/Jakarta')->subDays(self::GRACE_DAYS_AFTER_DUE)->toDateString();
        $n = 0;
        PlatformInvoice::where('status', 'unpaid')->where('due_on', '<', $cutoff)->get()->each(function (PlatformInvoice $inv) use (&$n) {
            $sub = $this->subscription($inv->billable_type, $inv->billable_id);
            if ($sub && $sub->status === 'active') {
                $sub->update(['status' => 'past_due']);
                $n++;
            }
            if ($inv->billable_type === 'tenant') {
                FeaturedPlacement::where('tenant_id', $inv->billable_id)->where('status', 'active')->get()->each(fn ($f) => $this->endFeatured($f, 'cancelled'));
            }
            $this->notifyBillable($inv, 'billing.past_due', "Tagihan {$inv->number} sudah lewat jatuh tempo. Fitur berbayar dinonaktifkan sementara sampai tagihan dilunasi. Data Anda tetap aman.");
        });

        return $n;
    }

    public function confirmPayment(PlatformInvoice $inv, ?string $proofFileId, ?string $note, User $actor): PlatformInvoice
    {
        if (! in_array($inv->status, ['unpaid', 'awaiting_confirmation'], true)) {
            throw ApiException::conflict('invoice_not_payable', 'Tagihan ini tidak menunggu pembayaran.');
        }
        $inv->update(['status' => 'awaiting_confirmation', 'proof_file_id' => $proofFileId, 'payer_note' => $note]);
        Audit::log('billing.payment_submitted', $inv, null, ['number' => $inv->number], $note, $inv->billable_type === 'tenant' ? $inv->billable_id : null, $inv->billable_type === 'tenant' ? 'tenant' : 'agent');

        return $inv;
    }

    public function markPaid(PlatformInvoice $inv, User $actor, ?string $note = null): PlatformInvoice
    {
        if (! in_array($inv->status, ['unpaid', 'awaiting_confirmation'], true)) {
            throw ApiException::conflict('invoice_not_payable', 'Tagihan ini sudah lunas atau dibatalkan.');
        }
        DB::transaction(function () use ($inv, $actor, $note) {
            $inv->update(['status' => 'paid', 'paid_at' => now(), 'reviewed_by' => $actor->id, 'review_note' => $note]);
            $sub = $this->subscription($inv->billable_type, $inv->billable_id);
            $stillOverdue = PlatformInvoice::where('billable_type', $inv->billable_type)->where('billable_id', $inv->billable_id)->where('status', 'unpaid')
                ->where('due_on', '<', now('Asia/Jakarta')->subDays(self::GRACE_DAYS_AFTER_DUE)->toDateString())->exists();
            if ($sub && $sub->status === 'past_due' && ! $stillOverdue) {
                $sub->update(['status' => 'active']);
            }
        });
        Audit::log('billing.invoice_paid', $inv, null, ['number' => $inv->number, 'total' => $inv->total], $note, $inv->billable_type === 'tenant' ? $inv->billable_id : null, 'platform');
        $this->notifyBillable($inv, 'billing.invoice_paid', "Pembayaran tagihan {$inv->number} sudah kami terima. Terima kasih.");

        return $inv;
    }

    public function rejectPayment(PlatformInvoice $inv, User $actor, string $note): PlatformInvoice
    {
        if ($inv->status !== 'awaiting_confirmation') {
            throw ApiException::conflict('invoice_not_pending', 'Tidak ada konfirmasi pembayaran untuk tagihan ini.');
        }
        $inv->update(['status' => 'unpaid', 'reviewed_by' => $actor->id, 'review_note' => $note]);
        Audit::log('billing.payment_rejected', $inv, null, ['number' => $inv->number], $note, $inv->billable_type === 'tenant' ? $inv->billable_id : null, 'platform');
        $this->notifyBillable($inv, 'billing.payment_rejected', "Konfirmasi pembayaran tagihan {$inv->number} belum bisa diterima: {$note}");

        return $inv;
    }

    public function void(PlatformInvoice $inv, User $actor, string $note): PlatformInvoice
    {
        if ($inv->status === 'paid') {
            throw ApiException::conflict('invoice_paid', 'Tagihan yang sudah lunas tidak bisa dibatalkan.');
        }
        DB::transaction(function () use ($inv, $actor, $note) {
            $inv->update(['status' => 'void', 'reviewed_by' => $actor->id, 'review_note' => $note]);
            TransactionFee::where('invoice_id', $inv->id)->update(['status' => 'waived']);
        });
        Audit::log('billing.invoice_voided', $inv, null, ['number' => $inv->number], $note, $inv->billable_type === 'tenant' ? $inv->billable_id : null, 'platform');

        return $inv;
    }

    // ---------------- Bantuan ----------------

    private function entity(string $type, string $id): \Illuminate\Database\Eloquent\Model
    {
        return $type === 'tenant' ? Tenant::findOrFail($id) : Agent::findOrFail($id);
    }

    private function notifyBillable(PlatformInvoice $inv, string $event, string $body): void
    {
        if ($inv->billable_type === 'tenant') {
            $this->notify->toTenant($inv->billable_id, ['owner', 'finance'], $event, ['body' => $body, 'url' => "/dasbor/ppiu/{$inv->billable_id}/langganan"], true);
        } else {
            $agent = Agent::with('user')->find($inv->billable_id);
            if ($agent?->user) {
                $this->notify->send($agent->user, $event, ['body' => $body, 'url' => '/agen/langganan'], null, true);
            }
        }
    }
}
