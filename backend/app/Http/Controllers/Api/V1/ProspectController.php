<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\Package;
use App\Models\Partnership;
use App\Models\Prospect;
use App\Support\OffsetCursor;
use App\Support\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** FR-AGT-07 Daftar prospek agen: catatan calon jamaah dan jadwal follow-up. Hanya terlihat oleh agen pemiliknya. */
class ProspectController extends Controller
{
    public const STATUSES = ['new', 'contacted', 'interested', 'booked', 'lost'];

    public function index(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $request->validate(['filter.follow_up_status' => ['nullable', Rule::in(self::STATUSES)], 'q' => ['nullable', 'string', 'max:100']]);
        $q = Prospect::with(['tenant', 'package'])->where('agent_id', $agent->id)
            ->when($request->input('filter.follow_up_status'), fn ($q, $s) => $q->where('follow_up_status', $s))
            ->when($request->input('q'), function ($q, $term) {
                $digits = ltrim((string) preg_replace('/\D/', '', $term), '0');
                $q->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                    ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%')));
            })
            ->orderByRaw('next_follow_up_on IS NULL, next_follow_up_on')->orderByDesc('updated_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 50);
        $counts = Prospect::where('agent_id', $agent->id)->selectRaw('follow_up_status s, count(*) c')->groupBy('follow_up_status')->pluck('c', 's');
        $due = Prospect::where('agent_id', $agent->id)->whereIn('follow_up_status', ['new', 'contacted', 'interested'])
            ->whereDate('next_follow_up_on', '<=', now('Asia/Jakarta')->toDateString())->count();

        return response()->json(['data' => $rows->map(fn ($p) => $this->present($p))->values(), 'meta' => $meta + ['counts' => $counts, 'due' => $due]]);
    }

    public function store(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $data = $this->validated($request, $agent, true);
        $p = Prospect::create($data + ['agent_id' => $agent->id, 'follow_up_status' => 'new']);

        return response()->json(['data' => $this->present($p->load(['tenant', 'package']))], 201);
    }

    public function update(Request $request, string $prospect): JsonResponse
    {
        $agent = $this->agent($request);
        $p = $this->find($agent, $prospect);
        $p->update($this->validated($request, $agent, false));

        return response()->json(['data' => $this->present($p->fresh(['tenant', 'package']))]);
    }

    public function destroy(Request $request, string $prospect): Response
    {
        $this->find($this->agent($request), $prospect)->delete();

        return response()->noContent();
    }

    private function validated(Request $request, Agent $agent, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'phone' => [$req, 'string', 'max:20'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'follow_up_status' => ['sometimes', Rule::in(self::STATUSES)],
            'next_follow_up_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'tenant_id' => ['sometimes', 'nullable', 'uuid'],
            'package_id' => ['sometimes', 'nullable', 'uuid'],
        ], ['name.required' => 'Isi nama calon jamaah.', 'phone.required' => 'Isi nomor HP/WhatsApp.']);

        if (isset($data['phone'])) {
            $data['phone'] = OtpService::normalizePhone($data['phone']);
            if (! preg_match('/^\+[1-9][0-9]{7,14}$/', $data['phone'])) {
                throw new ApiException(422, 'validation_failed', 'Nomor HP tidak valid.', errors: ['phone' => ['Contoh: 081234567890.']]);
            }
        }
        if (! empty($data['tenant_id'])) {
            $partner = Partnership::withoutTenantScope()->where('agent_id', $agent->id)->where('tenant_id', $data['tenant_id'])->where('status', 'active')->exists();
            if (! $partner) {
                throw new ApiException(422, 'validation_failed', 'Pilih PPIU yang sudah bermitra aktif dengan Anda.', errors: ['tenant_id' => ['Bukan mitra aktif.']]);
            }
        }
        if (! empty($data['package_id'])) {
            $tenantId = $data['tenant_id'] ?? null;
            $pkg = Package::withoutTenantScope()->whereKey($data['package_id'])->where('status', 'published')->first();
            if (! $pkg || ! $tenantId || $pkg->tenant_id !== $tenantId) {
                throw new ApiException(422, 'validation_failed', 'Paket harus paket terbit dari PPIU yang dipilih.', errors: ['package_id' => ['Paket tidak cocok.']]);
            }
        }
        if (array_key_exists('tenant_id', $data) && empty($data['tenant_id'])) {
            $data['package_id'] = null;
        }

        return $data;
    }

    private function find(Agent $agent, string $id): Prospect
    {
        return Prospect::where('agent_id', $agent->id)->whereKey($id)->firstOrFail();
    }

    private function agent(Request $request): Agent
    {
        return $request->attributes->get('agent');
    }

    private function present(Prospect $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'phone' => $p->phone,
            'notes' => $p->notes,
            'follow_up_status' => $p->follow_up_status,
            'next_follow_up_on' => $p->next_follow_up_on?->toDateString(),
            'tenant' => $p->tenant ? ['id' => $p->tenant->id, 'slug' => $p->tenant->slug, 'brand_name' => $p->tenant->brand_name] : null,
            'package' => $p->package ? ['id' => $p->package->id, 'slug' => $p->package->slug, 'name' => $p->package->name] : null,
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
