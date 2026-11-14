<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Tenant;
use App\Services\BookingService;
use App\Services\PilgrimService;
use App\Support\BookingPresenter as BP;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Booking dari tiga kanal: marketplace (jamaah), portal agen, dan walk-in dasbor PPIU (BP-05). */
class BookingController extends Controller
{
    private const STATUSES = ['awaiting_dp', 'dp_verified', 'fully_paid', 'departed', 'completed', 'expired', 'cancelled'];

    public function __construct(private BookingService $service) {}

    // ---------------- Jamaah (/me) ----------------

    public function myIndex(Request $request): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', Rule::in(self::STATUSES)]]);
        $uid = $request->user()->id;
        $q = Booking::withoutTenantScope()->with('tenant')
            ->where(fn ($w) => $w->where('booker_user_id', $uid)->orWhereIn('id',
                BookingParticipant::withoutTenantScope()->select('booking_id')->whereIn('pilgrim_id', \App\Models\Pilgrim::where('user_id', $uid)->select('id'))))
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);

        return response()->json(['data' => $rows->map(fn ($b) => BP::summary($b))->values(), 'meta' => $meta]);
    }

    public function myShow(Request $request, string $booking): JsonResponse
    {
        $b = Booking::withoutTenantScope()->with('tenant')->where('booker_user_id', $request->user()->id)->whereKey($booking)->firstOrFail();

        return response()->json(['data' => BP::full($b)]);
    }

    public function myStore(Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        $b = $this->service->create($data, ['channel' => 'marketplace', 'booker' => $request->user(), 'actor' => $request->user()]);

        return response()->json(['data' => BP::full($b->fresh()->load('tenant'))], 201);
    }

    // ---------------- Agen ----------------

    public function agentIndex(Request $request): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $request->validate(['filter.status' => ['nullable', Rule::in(self::STATUSES)], 'filter.tenant_id' => ['nullable', 'uuid']]);
        $q = Booking::withoutTenantScope()->with('tenant')->where('agent_id', $agent->id)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.tenant_id'), fn ($q, $s) => $q->where('tenant_id', $s))
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);

        return response()->json(['data' => $rows->map(fn ($b) => BP::summary($b))->values(), 'meta' => $meta]);
    }

    public function agentShow(Request $request, string $booking): JsonResponse
    {
        $agent = $request->attributes->get('agent');
        $b = Booking::withoutTenantScope()->with('tenant')->where('agent_id', $agent->id)->whereKey($booking)->firstOrFail();

        return response()->json(['data' => BP::agentView($b)]);
    }

    public function agentStore(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $b = $this->service->create($data, [
            'channel' => 'agent_portal', 'booker' => $this->service->bookerFromInput($data['booker']),
            'actor' => $request->user(), 'agent' => $request->attributes->get('agent'),
        ]);

        return response()->json(['data' => BP::agentView($b->fresh()->load('tenant'))], 201);
    }

    // ---------------- PPIU ----------------

    public function tenantIndex(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate([
            'filter.status' => ['nullable', Rule::in(self::STATUSES)], 'filter.departure_id' => ['nullable', 'uuid'],
            'filter.agent_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $q = Booking::with('tenant')->where('tenant_id', $tenant->id)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('filter.departure_id'), fn ($q, $s) => $q->where('departure_id', $s))
            ->when($request->input('filter.agent_id'), fn ($q, $s) => $q->where('agent_id', $s))
            ->when($request->input('q'), function ($q, $s) {
                $q->where(fn ($w) => $w->where('code', 'ilike', "%{$s}%")
                    ->orWhereIn('id', BookingParticipant::withoutTenantScope()->select('booking_id')
                        ->whereIn('pilgrim_id', \App\Models\Pilgrim::where('full_name', 'ilike', "%{$s}%")->select('id'))));
            })
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);
        $counts = Booking::where('tenant_id', $tenant->id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => $rows->map(fn ($b) => BP::summary($b, true))->values(), 'meta' => $meta + ['counts' => $counts]]);
    }

    public function tenantShow(Tenant $tenant, string $booking): JsonResponse
    {
        $b = Booking::with('tenant')->where('tenant_id', $tenant->id)->whereKey($booking)->firstOrFail();

        return response()->json(['data' => BP::full($b, true)]);
    }

    public function tenantStore(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $this->validated($request, true, true);
        $b = $this->service->create($data, [
            'channel' => 'ppiu_dashboard', 'booker' => $this->service->bookerFromInput($data['booker']),
            'actor' => $request->user(), 'staff_agent_id' => $data['agent_id'] ?? null, 'tenant' => $tenant,
        ]);

        return response()->json(['data' => BP::full($b->fresh()->load('tenant'), true)], 201);
    }

    private function validated(Request $request, bool $byStaff, bool $tenantStaff = false): array
    {
        $participants = collect($request->input('participants', []))->map(function ($p) {
            if (isset($p['pilgrim']['nik'])) {
                $p['pilgrim']['nik'] = preg_replace('/\D/', '', (string) $p['pilgrim']['nik']);
            }

            return $p;
        })->all();
        $request->merge(['participants' => $participants]);

        $rules = [
            'departure_id' => ['required', 'uuid'],
            'participants' => ['required', 'array', 'min:1', 'max:'.BookingService::MAX_PARTICIPANTS],
            'participants.*.room_type' => ['required', 'in:quad,triple,double'],
            'participants.*.pilgrim_id' => ['nullable', 'uuid'],
            'participants.*.pilgrim' => ['nullable', 'required_without:participants.*.pilgrim_id', 'array'],
            'accept_package_terms' => ['required', 'accepted'],
            'referral_code' => ['nullable', 'string', 'max:16'],
            'agent_code' => ['nullable', 'string', 'max:16'],
        ];
        foreach (array_keys($participants) as $i) {
            if (empty($participants[$i]['pilgrim_id'])) {
                $rules += PilgrimService::rules("participants.$i.pilgrim.");
            }
        }
        if ($byStaff) {
            $rules += ['booker.name' => ['required', 'string', 'max:150'], 'booker.phone' => ['required', 'string', 'max:20']];
        }
        if ($tenantStaff) {
            $rules['agent_id'] = ['nullable', 'uuid'];
        }
        $messages = [
            'accept_package_terms.accepted' => 'Setujui syarat paket dan kebijakan pembatalan untuk melanjutkan.',
            'participants.*.pilgrim.nik.regex' => 'NIK terdiri dari 16 angka.',
            'participants.*.pilgrim.birth_date.before' => 'Tanggal lahir tidak valid.',
            'booker.phone.required' => 'Isi nomor HP pemesan.',
        ];

        return $request->validate($rules, $messages);
    }
}
