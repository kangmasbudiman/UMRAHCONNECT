<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Pilgrim;
use App\Services\PilgrimService;
use App\Support\AgentPresenter;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Data jamaah yang dikelola pengguna (FR-JMH-01). */
class PilgrimController extends Controller
{
    public static function present(Pilgrim $p): array
    {
        return [
            'id' => $p->id, 'full_name' => $p->full_name, 'nik_masked' => AgentPresenter::mask($p->nik_enc),
            'birth_place' => $p->birth_place, 'birth_date' => $p->birth_date?->toDateString(), 'gender' => $p->gender,
            'passport_no_masked' => AgentPresenter::mask($p->passport_no_enc, 0, 3), 'passport_expires_on' => $p->passport_expires_on?->toDateString(),
            'phone' => $p->phone, 'emergency_contact' => $p->emergency_contact, 'mahram_pilgrim_id' => $p->mahram_pilgrim_id,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $q = Pilgrim::where('managed_by_user_id', $request->user()->id)->orderBy('full_name')->orderBy('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 50, 100);

        return response()->json(['data' => $rows->map(fn ($p) => self::present($p))->values(), 'meta' => $meta]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['nik' => preg_replace('/\D/', '', (string) $request->input('nik'))]);
        $data = $request->validate(PilgrimService::rules(), PilgrimService::messages());
        $exists = Pilgrim::where('managed_by_user_id', $request->user()->id)->where('nik_hash', PilgrimService::nikHash($data['nik']))->exists();
        if ($exists) {
            throw new ApiException(409, 'pilgrim_exists', 'Jamaah dengan NIK ini sudah ada di daftar Anda.', errors: ['nik' => ['Sudah ada.']]);
        }

        return response()->json(['data' => self::present(PilgrimService::upsert($request->user(), $data))], 201);
    }

    public function show(Request $request, string $pilgrim): JsonResponse
    {
        return response()->json(['data' => self::present($this->find($request, $pilgrim))]);
    }

    public function update(Request $request, string $pilgrim): JsonResponse
    {
        $p = $this->find($request, $pilgrim);
        $data = $request->validate(collect(PilgrimService::rules('', false))->except('nik')->all() + ['nik' => ['prohibited']],
            ['nik.prohibited' => 'NIK tidak bisa diubah. Hapus lalu tambahkan data baru bila salah.'] + PilgrimService::messages());
        $p->update(PilgrimService::attrs($data));

        return response()->json(['data' => self::present($p->fresh())]);
    }

    public function destroy(Request $request, string $pilgrim): Response
    {
        $p = $this->find($request, $pilgrim);
        $active = BookingParticipant::withoutTenantScope()->where('pilgrim_id', $p->id)->where('is_active', true)
            ->whereIn('booking_id', Booking::withoutTenantScope()->whereIn('status', Booking::ACTIVE)->select('id'))->exists();
        if ($active) {
            throw ApiException::conflict('pilgrim_in_active_booking', 'Jamaah ini terdaftar di booking aktif dan belum bisa dihapus.');
        }
        $p->delete();

        return response()->noContent();
    }

    private function find(Request $request, string $id): Pilgrim
    {
        return Pilgrim::where('managed_by_user_id', $request->user()->id)->whereKey($id)->firstOrFail();
    }
}
