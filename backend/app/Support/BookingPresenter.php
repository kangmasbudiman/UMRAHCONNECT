<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\PilgrimDocumentController;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PilgrimDocument;
use App\Models\Refund;

/** Bentuk respons Booking (pemesan & PPIU) dan BookingAgentView (tanpa data identitas, BR-14). */
class BookingPresenter
{
    public static function invoice(Invoice $i, ?string $bookingCode = null): array
    {
        $bank = $i->bankAccount;

        return [
            'id' => $i->id, 'number' => $i->number, 'booking_id' => $i->booking_id, 'booking_code' => $bookingCode,
            'type' => $i->type, 'amount' => $i->amount, 'paid_amount' => $i->paid_amount,
            'due_at' => $i->due_at->toIso8601String(), 'status' => $i->status,
            'pay_to' => $bank ? ['bank_name' => $bank->bank_name, 'account_no' => $bank->account_no, 'account_holder' => $bank->account_holder] : null,
        ];
    }

    public static function payment(Payment $p, bool $withProof = false): array
    {
        $p->loadMissing(['invoice', 'receipt']);

        return [
            'id' => $p->id, 'invoice_id' => $p->invoice_id, 'invoice_number' => $p->invoice?->number, 'invoice_type' => $p->invoice?->type,
            'method' => $p->method, 'claimed_amount' => $p->claimed_amount, 'verified_amount' => $p->verified_amount,
            'paid_on' => $p->paid_on?->toDateString(), 'sender_bank' => $p->sender_bank, 'sender_name' => $p->sender_name,
            'proof_file_id' => $p->proof_file_id,
            'proof_url' => ($withProof && $p->proof_file_id && ($f = $p->proof ?? \App\Models\File::find($p->proof_file_id))) ? FileController::present($f)['url'] : null,
            'status' => $p->status, 'reject_reason' => $p->reject_reason, 'split_from_payment_id' => $p->split_from_payment_id,
            'receipt' => $p->receipt ? ['id' => $p->receipt->id, 'number' => $p->receipt->number] : null,
            'created_at' => $p->created_at?->toIso8601String(), 'verified_at' => $p->verified_at?->toIso8601String(),
        ];
    }

    public static function refund(Refund $r): array
    {
        return [
            'id' => $r->id, 'booking_id' => $r->booking_id, 'type' => $r->type, 'amount' => $r->amount,
            'target_booking_id' => $r->target_booking_id,
            'target_booking_code' => $r->target_booking_id ? Booking::withoutTenantScope()->whereKey($r->target_booking_id)->value('code') : null,
            'paid_on' => $r->paid_on->toDateString(), 'proof_file_id' => $r->proof_file_id, 'note' => $r->note,
            'recorded_by' => $r->recorded_by, 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private static function payments(Booking $b, bool $withProof): array
    {
        return $b->payments()->with(['invoice', 'receipt', 'proof'])->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($p) => self::payment($p, $withProof))->values()->all();
    }

    private static function base(Booking $b): array
    {
        $b->loadMissing(['departure', 'package', 'agent.user', 'invoices.bankAccount', 'booker']);
        $t = $b->tenant;

        return [
            'id' => $b->id,
            'code' => $b->code,
            'status' => $b->status,
            'tenant' => ['id' => $t->id, 'slug' => $t->slug, 'brand_name' => $t->brand_name, 'whatsapp' => $t->whatsapp],
            'package' => ['id' => $b->package->id, 'name' => $b->package->name, 'slug' => $b->package->slug, 'duration_days' => $b->package->duration_days, 'airline' => $b->package->airline],
            'departure' => [
                'id' => $b->departure->id, 'departure_date' => $b->departure->departure_date->toDateString(),
                'return_date' => $b->departure->return_date->toDateString(), 'departure_city' => $b->departure->departure_city,
                'final_payment_due_on' => $b->departure->final_payment_due_on->toDateString(),
            ],
            'booker' => ['name' => $b->booker->name, 'phone' => $b->booker->phone],
            'total_amount' => $b->total_amount,
            'paid_amount' => $b->paid_amount,
            'invoices' => $b->invoices->map(fn ($i) => self::invoice($i, $b->code))->values(),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }

    /** Pemesan dan PPIU. $tenantView menambah NIK/paspor tersamar dan data internal. */
    public static function full(Booking $b, bool $tenantView = false): array
    {
        $b->loadMissing(['participants.pilgrim']);
        $out = self::base($b) + [
            'agent' => $b->agent ? AgentPresenter::agentRef($b->agent, $tenantView) : null,
            'attribution_source' => $b->attribution_source,
            'channel' => $b->channel,
            'participant_count' => $b->participant_count,
            'participants' => $b->participants->where('is_active', true)->values()->map(fn ($p) => [
                'pilgrim_id' => $p->pilgrim_id, 'full_name' => $p->pilgrim->full_name, 'gender' => $p->pilgrim->gender,
                'room_type' => $p->room_type, 'price_amount' => $p->price_amount,
            ] + ($tenantView ? [
                'nik_masked' => AgentPresenter::mask($p->pilgrim->nik_enc),
                'passport_no_masked' => AgentPresenter::mask($p->pilgrim->passport_no_enc, 0, 3),
                'birth_date' => $p->pilgrim->birth_date?->toDateString(),
            ] : []))->values(),
            'dp_amount' => $b->dp_amount,
            'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
            'pay_to' => ($b->invoices->first()?->bankAccount) ? self::invoice($b->invoices->first())['pay_to'] : null,
            'cancel_request' => $b->cancel_request_status ? ['status' => $b->cancel_request_status, 'requested_at' => $b->cancel_requested_at?->toIso8601String(), 'reason' => $b->cancel_request_reason] : null,
            'refund_decided_amount' => $b->refund_decided_amount,
            'refund_basis' => $b->refund_basis,
            'confirmed_at' => $b->confirmed_at?->toIso8601String(),
            'cancelled_at' => $b->cancelled_at?->toIso8601String(),
            'cancel_reason' => $b->cancel_reason,
        ];
        $out['payments'] = self::payments($b, true);
        $out['refunds'] = $b->refunds->map(fn ($r) => self::refund($r))->values();
        $out['refund_total_amount'] = (int) $b->refunds->sum('amount');
        if ($tenantView) {
            $out['commission_snapshot'] = $b->commission_snapshot;
            $docs = PilgrimDocument::with(['file', 'reviews'])->whereIn('pilgrim_id', $b->participants->pluck('pilgrim_id'))->where('status', 'active')->get()->groupBy('pilgrim_id');
            $out['participants'] = collect($out['participants'])->map(fn ($pt) => $pt + [
                'documents' => ($docs[$pt['pilgrim_id']] ?? collect())->map(fn ($d) => PilgrimDocumentController::present($d, $b->tenant_id))->values(),
            ])->values();
            $out['notes_count'] = $b->notes()->count();
        }
        if ($b->participants->where('is_active', false)->isNotEmpty() && $out['participants']->isEmpty()) {
            // Booking kedaluwarsa/batal: tetap tampilkan peserta (nonaktif) agar riwayat terbaca.
            $out['participants'] = $b->participants->map(fn ($p) => ['pilgrim_id' => $p->pilgrim_id, 'full_name' => $p->pilgrim->full_name, 'gender' => $p->pilgrim->gender, 'room_type' => $p->room_type, 'price_amount' => $p->price_amount])->values();
        }

        return $out;
    }

    /** Versi agen: tanpa NIK, paspor, tanggal lahir, catatan internal (BR-14). */
    public static function agentView(Booking $b): array
    {
        $b->loadMissing(['participants.pilgrim']);

        return self::base($b) + [
            'participant_count' => $b->participant_count,
            'participants' => $b->participants->map(fn ($p) => ['full_name' => $p->pilgrim->full_name, 'room_type' => $p->room_type])->values(),
            'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
            'attribution_source' => $b->attribution_source,
            'cancel_request_status' => $b->cancel_request_status,
            'refund_total_amount' => (int) $b->refunds()->sum('amount'),
            'payments' => collect(self::payments($b, false))->map(fn ($p) => array_diff_key($p, ['proof_file_id' => 1, 'proof_url' => 1, 'sender_bank' => 1]))->values(),
        ];
    }

    /** Ringkasan untuk daftar. */
    public static function summary(Booking $b, bool $withAgent = false): array
    {
        $b->loadMissing(['departure', 'package', 'booker', 'agent.user']);

        return [
            'id' => $b->id, 'code' => $b->code, 'status' => $b->status,
            'tenant' => ['id' => $b->tenant->id, 'brand_name' => $b->tenant->brand_name, 'slug' => $b->tenant->slug],
            'package' => ['id' => $b->package->id, 'name' => $b->package->name],
            'departure' => ['id' => $b->departure->id, 'departure_date' => $b->departure->departure_date->toDateString(), 'departure_city' => $b->departure->departure_city],
            'booker' => ['name' => $b->booker->name, 'phone' => $b->booker->phone],
            'participant_count' => $b->participant_count,
            'total_amount' => $b->total_amount, 'dp_amount' => $b->dp_amount, 'paid_amount' => $b->paid_amount,
            'hold_expires_at' => $b->hold_expires_at?->toIso8601String(),
            'channel' => $b->channel, 'attribution_source' => $b->attribution_source,
            'created_at' => $b->created_at?->toIso8601String(),
        ] + ($withAgent ? ['agent' => $b->agent ? AgentPresenter::agentRef($b->agent) : null] : []);
    }
}
