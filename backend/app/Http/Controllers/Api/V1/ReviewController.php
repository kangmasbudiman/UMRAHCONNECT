<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Package;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\NotificationService;
use App\Support\Audit;
use App\Support\OffsetCursor;
use App\Support\Ratings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ulasan terverifikasi: hanya pemesan yang bookingnya sudah berangkat/selesai lewat UMRAH CONNECT.
 * Satu ulasan per booking; PPIU boleh membalas; staf platform bisa menyembunyikan ulasan yang melanggar.
 */
class ReviewController extends Controller
{
    public const ELIGIBLE = ['departed', 'completed'];

    public function __construct(private NotificationService $notify) {}

    public function store(Request $request, string $booking): JsonResponse
    {
        $b = Booking::withoutTenantScope()->where('booker_user_id', $request->user()->id)->whereKey($booking)->firstOrFail();
        if (! in_array($b->status, self::ELIGIBLE, true)) {
            throw new ApiException(422, 'review_not_eligible', 'Ulasan bisa ditulis setelah Anda berangkat umrah dengan booking ini.');
        }
        if (Review::withoutTenantScope()->where('booking_id', $b->id)->exists()) {
            throw ApiException::conflict('review_exists', 'Anda sudah menulis ulasan untuk booking ini.');
        }
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:20', 'max:2000'],
        ], ['body.min' => 'Ceritakan pengalaman Anda minimal 20 karakter.', 'rating.required' => 'Pilih jumlah bintang.']);
        $r = Review::create(['tenant_id' => $b->tenant_id, 'booking_id' => $b->id, 'package_id' => $b->package_id, 'user_id' => $request->user()->id]
            + $data + ['body' => trim($data['body'])]);

        return response()->json(['data' => self::present($r->load('user'))], 201);
    }

    /** GET /public/packages/{tenant}/{package}/reviews */
    public function publicIndex(Request $request, string $tenantSlug, string $packageSlug): JsonResponse
    {
        $pkg = Package::publiclyVisible()->whereHas('tenant', fn ($t) => $t->where('slug', $tenantSlug))->where('slug', $packageSlug)->firstOrFail();
        $q = Review::withoutTenantScope()->with(['user', 'booking.departure'])->where('package_id', $pkg->id)->where('status', 'published')
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 10, 50);
        $dist = Review::withoutTenantScope()->where('package_id', $pkg->id)->where('status', 'published')->selectRaw('rating, count(*) c')->groupBy('rating')->pluck('c', 'rating');

        return response()->json([
            'data' => $rows->map(fn ($r) => self::present($r, true))->values(),
            'meta' => $meta + ['summary' => (Ratings::forPackages([$pkg->id])[$pkg->id] ?? ['avg' => null, 'count' => 0]) + ['distribution' => $dist], 'tenant' => Ratings::forTenant($pkg->tenant_id)],
        ])->header('Cache-Control', 'public, max-age=60');
    }

    public function tenantIndex(Request $request, Tenant $tenant): JsonResponse
    {
        $q = Review::with(['user', 'package', 'booking'])->where('tenant_id', $tenant->id)
            ->when($request->query('unreplied'), fn ($q) => $q->whereNull('tenant_reply'))
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 30);

        return response()->json(['data' => $rows->map(fn ($r) => self::present($r) + ['package' => ['id' => $r->package->id, 'name' => $r->package->name], 'booking_code' => $r->booking->code])->values(),
            'meta' => $meta + ['summary' => Ratings::forTenant($tenant->id)]]);
    }

    public function reply(Request $request, Tenant $tenant, string $review): JsonResponse
    {
        $r = Review::with('user')->where('tenant_id', $tenant->id)->whereKey($review)->firstOrFail();
        $data = $request->validate(['reply' => ['required', 'string', 'min:5', 'max:1000']]);
        $r->update(['tenant_reply' => trim($data['reply']), 'replied_at' => now(), 'replied_by' => $request->user()->id]);
        Audit::log('review.replied', $r, null, ['reply' => $r->tenant_reply], tenantId: $tenant->id, context: 'tenant');
        $this->notify->send($r->user, 'review.replied', ['body' => "{$tenant->brand_name} membalas ulasan Anda: \"".mb_strimwidth($r->tenant_reply, 0, 140, '…').'"'], $tenant->id);

        return response()->json(['data' => self::present($r)]);
    }

    public function platformIndex(Request $request): JsonResponse
    {
        $q = Review::withoutTenantScope()->with(['user', 'package', 'booking'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 30);
        $tenants = Tenant::whereIn('id', $rows->pluck('tenant_id'))->pluck('brand_name', 'id');

        return response()->json(['data' => $rows->map(fn ($r) => self::present($r) + ['package' => ['id' => $r->package->id, 'name' => $r->package->name], 'tenant_name' => $tenants[$r->tenant_id] ?? null])->values(), 'meta' => $meta]);
    }

    public function hide(Request $request, string $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $r = Review::withoutTenantScope()->with('user')->findOrFail($review);
        $r->update(['status' => 'hidden', 'hidden_reason' => $data['reason']]);
        Audit::log('review.hidden', $r, ['status' => 'published'], ['status' => 'hidden'], $data['reason'], $r->tenant_id, 'platform');

        return response()->json(['data' => self::present($r)]);
    }

    public function unhide(string $review): JsonResponse
    {
        $r = Review::withoutTenantScope()->with('user')->findOrFail($review);
        $r->update(['status' => 'published', 'hidden_reason' => null]);
        Audit::log('review.unhidden', $r, ['status' => 'hidden'], ['status' => 'published'], null, $r->tenant_id, 'platform');

        return response()->json(['data' => self::present($r)]);
    }

    /** Nama pengulas disingkat untuk publik (mis. "Hasan B."). */
    public static function present(Review $r, bool $public = false): array
    {
        $name = trim((string) $r->user?->name);
        $parts = preg_split('/\s+/', $name) ?: [];
        $short = $parts ? $parts[0].(isset($parts[1]) ? ' '.mb_substr($parts[1], 0, 1).'.' : '') : 'Jamaah';
        $dep = $r->relationLoaded('booking') ? $r->booking?->departure : null;

        return [
            'id' => $r->id, 'rating' => $r->rating, 'body' => $r->body, 'status' => $r->status,
            'author' => $public ? $short : $name,
            'verified_departure' => $dep ? ['departure_date' => $dep->departure_date->toDateString()] : null,
            'tenant_reply' => $r->tenant_reply, 'replied_at' => $r->replied_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ] + ($public ? [] : ['hidden_reason' => $r->hidden_reason]);
    }
}
