<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\DepartureMilestone;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\Journey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * "Lacak Umrah": PPIU memperbarui tahap persiapan per keberangkatan; pemesan diberi tahu.
 * Pelacakan publik: kode booking + 4 digit akhir nomor HP pemesan (untuk jamaah tanpa akun, mis. walk-in lewat agen).
 */
class MilestoneController extends Controller
{
    public function __construct(private NotificationService $notify) {}

    public function index(Tenant $tenant, string $departure): JsonResponse
    {
        $dep = $this->departure($tenant, $departure);

        return response()->json(['data' => $this->present($dep)]);
    }

    public function update(Request $request, Tenant $tenant, string $departure, string $type): JsonResponse
    {
        abort_unless(isset(DepartureMilestone::TYPES[$type]), 404);
        $dep = $this->departure($tenant, $departure);
        if ($dep->status === 'cancelled') {
            throw ApiException::conflict('departure_cancelled', 'Keberangkatan ini sudah dibatalkan.');
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'done'])],
            'happened_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($type === 'manasik' && $data['status'] === 'done' && empty($data['happened_on'])) {
            throw new ApiException(422, 'validation_failed', 'Isi tanggal manasik.', errors: ['happened_on' => ['Tanggal manasik wajib diisi.']]);
        }
        $m = DepartureMilestone::firstOrNew(['tenant_id' => $tenant->id, 'departure_id' => $dep->id, 'type' => $type]);
        $wasDone = $m->exists && $m->status === 'done';
        $old = $m->exists ? $m->only(['status', 'happened_on', 'note']) : null;
        $m->fill([
            'status' => $data['status'],
            'happened_on' => $data['happened_on'] ?? ($data['status'] === 'done' ? now('Asia/Jakarta')->toDateString() : null),
            'note' => $data['note'] ?? null,
            'updated_by' => $request->user()->id,
        ])->save();
        Audit::log('departure.milestone_updated', $dep, $old, ['type' => $type] + $m->only(['status', 'note']) + ['happened_on' => $m->happened_on?->toDateString()], tenantId: $tenant->id, context: 'tenant');

        if ($m->status === 'done' && ! $wasDone) {
            $label = DepartureMilestone::TYPES[$type];
            $pkg = $dep->package()->withoutTenantScope()->first();
            $when = $type === 'manasik' ? ' pada '.$m->happened_on->translatedFormat('j F Y') : '';
            Booking::withoutTenantScope()->where('departure_id', $dep->id)->whereIn('status', ['awaiting_dp', 'dp_verified', 'fully_paid'])
                ->get()->each(function (Booking $b) use ($label, $pkg, $when, $m, $tenant) {
                    $user = User::find($b->booker_user_id);
                    if ($user && $user->status !== 'unclaimed') {
                        $this->notify->send($user, 'departure.milestone', ['title' => "Lacak Umrah: {$label}", 'body' => "{$label}{$when} untuk {$pkg->name} (booking {$b->code}).".($m->note ? " {$m->note}" : ''), 'url' => "/akun/booking/{$b->id}"], $tenant->id, true);
                    }
                });
        }

        return response()->json(['data' => $this->present($dep->fresh())]);
    }

    /** GET /public/track?code=UC-2611-0001&phone=1234 — tanpa data pribadi. */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'digits:4'],
        ], ['phone.digits' => 'Masukkan 4 digit terakhir nomor HP pemesan.']);
        $code = Str::upper(trim($data['code']));
        // Kode booking berurutan dan "kunci"-nya hanya 4 digit: setelah 5 kali salah, kode ini dikunci 1 jam
        // (berlaku untuk semua IP), di samping batas per IP. Kode yang tidak ada diperlakukan sama agar tidak bisa dipetakan.
        $lockKey = 'track-fail:'.hash('sha256', $code);
        if (RateLimiter::tooManyAttempts($lockKey, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($lockKey) / 60);
            throw new ApiException(429, 'too_many_attempts', "Terlalu banyak percobaan untuk kode booking ini. Coba lagi dalam {$minutes} menit, atau hubungi PPIU/agen Anda.");
        }
        $b = Booking::withoutTenantScope()->with(['booker', 'departure', 'package', 'tenant'])->where('code', $code)->first();
        $phone = (string) ($b?->booker?->phone ?? '');
        if (! $b || $phone === '' || ! hash_equals(substr($phone, -4), $data['phone'])) {
            RateLimiter::hit($lockKey, 3600);
            throw new ApiException(404, 'not_found', 'Booking tidak ditemukan. Periksa kode booking dan 4 digit terakhir nomor HP pemesan.');
        }
        RateLimiter::clear($lockKey);

        return response()->json(['data' => [
            'code' => $b->code,
            'status' => $b->status,
            'package' => ['name' => $b->package->name, 'duration_days' => $b->package->duration_days],
            'tenant' => ['brand_name' => $b->tenant->brand_name, 'slug' => $b->tenant->slug, 'whatsapp' => $b->tenant->whatsapp, 'ppiu_license_no' => $b->tenant->ppiu_license_no],
            'departure' => ['departure_date' => $b->departure->departure_date->toDateString(), 'return_date' => $b->departure->return_date->toDateString(), 'departure_city' => $b->departure->departure_city],
            'participant_count' => $b->participant_count,
            'journey' => Journey::forBooking($b),
        ]]);
    }

    private function departure(Tenant $tenant, string $id): Departure
    {
        return Departure::where('tenant_id', $tenant->id)->whereKey($id)->with('package')->firstOrFail();
    }

    private function present(Departure $dep): array
    {
        $rows = DepartureMilestone::where('departure_id', $dep->id)->get()->keyBy('type');
        $active = Booking::where('departure_id', $dep->id)->whereIn('status', ['awaiting_dp', 'dp_verified', 'fully_paid'])->count();

        return [
            'departure' => ['id' => $dep->id, 'departure_date' => $dep->departure_date->toDateString(), 'return_date' => $dep->return_date->toDateString(),
                'departure_city' => $dep->departure_city, 'status' => $dep->status, 'package' => ['id' => $dep->package->id, 'name' => $dep->package->name]],
            'active_bookings' => $active,
            'milestones' => collect(DepartureMilestone::TYPES)->map(function ($label, $type) use ($rows) {
                $m = $rows->get($type);

                return ['type' => $type, 'label' => $label, 'status' => $m->status ?? 'pending', 'happened_on' => $m?->happened_on?->toDateString(),
                    'note' => $m?->note, 'updated_at' => $m?->updated_at?->toIso8601String()];
            })->values(),
        ];
    }
}
