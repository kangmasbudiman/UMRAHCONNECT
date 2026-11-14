<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\SavingsEntry;
use App\Models\SavingsPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Rencana Tabungan Umrah: target biaya, tanggal, setoran per bulan, catatan setoran, pengingat bulanan.
 * UMRAH CONNECT hanya mencatat; uang tetap disimpan jamaah sendiri (tabungan bank/tabungan haji-umrah).
 */
class SavingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = SavingsPlan::with(['package.tenant', 'entries' => fn ($q) => $q->orderByDesc('saved_on')->orderByDesc('created_at')->limit(50)])
            ->where('user_id', $request->user()->id)->where('status', '!=', 'archived')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->orderBy('target_date')->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->present($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $plan = new SavingsPlan(['user_id' => $request->user()->id, 'status' => 'active'] + $data);
        DB::transaction(function () use ($plan, $data) {
            $initial = (int) ($data['initial_amount'] ?? 0);
            unset($plan->initial_amount);
            $plan->saved_amount = max(0, $initial);
            $plan->recalc();
            $plan->save();
            if ($initial > 0) {
                SavingsEntry::create(['plan_id' => $plan->id, 'amount' => $initial, 'saved_on' => now('Asia/Jakarta')->toDateString(), 'note' => 'Saldo awal']);
            }
        });

        return response()->json(['data' => $this->present($plan->fresh(['package.tenant', 'entries']))], 201);
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        $p = $this->find($request, $plan);
        $data = $this->validated($request, false);
        unset($data['initial_amount']);
        $p->fill($data);
        $p->recalc();
        $p->save();

        return response()->json(['data' => $this->present($p->fresh(['package.tenant', 'entries']))]);
    }

    public function destroy(Request $request, string $plan): Response
    {
        $this->find($request, $plan)->delete();

        return response()->noContent();
    }

    public function addEntry(Request $request, string $plan): JsonResponse
    {
        $p = $this->find($request, $plan);
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000000,1000000000'],
            'saved_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:200'],
        ], ['amount.not_in' => 'Isi nominal setoran.']);
        if ($p->saved_amount + $data['amount'] < 0) {
            throw new ApiException(422, 'validation_failed', 'Penarikan melebihi jumlah yang sudah terkumpul.', errors: ['amount' => ['Melebihi saldo tercatat.']]);
        }
        DB::transaction(function () use ($p, $data) {
            SavingsEntry::create(['plan_id' => $p->id, 'amount' => $data['amount'], 'saved_on' => $data['saved_on'] ?? now('Asia/Jakarta')->toDateString(), 'note' => $data['note'] ?? null]);
            $p->saved_amount += $data['amount'];
            $p->recalc();
            $p->save();
        });

        return response()->json(['data' => $this->present($p->fresh(['package.tenant', 'entries']))], 201);
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:150'],
            'package_id' => ['sometimes', 'nullable', 'uuid'],
            'persons' => [$req, 'integer', 'between:1,20'],
            'target_amount' => ['sometimes', 'nullable', 'integer', 'min:1000000', 'max:5000000000'],
            'target_date' => [$req, 'date_format:Y-m-d', 'after:today'],
            'reminder_day' => ['sometimes', 'nullable', 'integer', 'between:1,28'],
            'initial_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:5000000000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ], ['target_date.after' => 'Tanggal target harus setelah hari ini.']);

        $pkg = null;
        if (! empty($data['package_id'])) {
            $pkg = Package::publiclyVisible()->with(['departures.prices'])->whereKey($data['package_id'])->first()
                ?? throw new ApiException(422, 'validation_failed', 'Paket tidak ditemukan atau sudah tidak dijual.', errors: ['package_id' => ['Tidak tersedia.']]);
        }
        if ($creating && empty($data['target_amount'])) {
            $from = $pkg?->departures->flatMap->prices->min('price_amount');
            if (! $from) {
                throw new ApiException(422, 'validation_failed', 'Isi target biaya, atau pilih paket yang punya harga.', errors: ['target_amount' => ['Wajib diisi.']]);
            }
            $data['target_amount'] = (int) $from * (int) $data['persons'];
        }
        if ($creating && empty($data['label'])) {
            $data['label'] = $pkg ? $pkg->name : 'Umrah '.\Illuminate\Support\Carbon::parse($data['target_date'])->translatedFormat('F Y');
        }

        return $data;
    }

    private function find(Request $request, string $id): SavingsPlan
    {
        return SavingsPlan::where('user_id', $request->user()->id)->whereKey($id)->firstOrFail();
    }

    private function present(SavingsPlan $p): array
    {
        $months = max(0, (int) floor(now('Asia/Jakarta')->startOfMonth()->diffInMonths($p->target_date->copy()->startOfMonth(), false)));
        $pkg = $p->package;

        return [
            'id' => $p->id, 'label' => $p->label, 'persons' => $p->persons, 'status' => $p->status,
            'target_amount' => $p->target_amount, 'saved_amount' => $p->saved_amount, 'monthly_amount' => $p->monthly_amount,
            'remaining_amount' => max(0, $p->target_amount - $p->saved_amount),
            'progress' => (int) min(100, floor($p->saved_amount / max(1, $p->target_amount) * 100)),
            'target_date' => $p->target_date->toDateString(), 'months_left' => $months, 'reminder_day' => $p->reminder_day,
            'package' => $pkg ? ['id' => $pkg->id, 'name' => $pkg->name, 'slug' => $pkg->slug, 'tenant_slug' => $pkg->tenant?->slug, 'tenant_name' => $pkg->tenant?->brand_name] : null,
            'entries' => $p->entries->map(fn ($e) => ['id' => $e->id, 'amount' => $e->amount, 'saved_on' => $e->saved_on->toDateString(), 'note' => $e->note])->values(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
