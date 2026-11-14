<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageImage;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\CatalogPresenter as P;
use App\Support\Etag;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Editor paket PPIU (BP-02, FR-PPIU-04, FR-PPIU-02). Data terisolasi per tenant lewat scope + tenant_id eksplisit. */
class PackageController extends Controller
{
    public const MAX_IMAGES = 10;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $request->validate(['filter.status' => ['nullable', Rule::in(['draft', 'published', 'archived', 'taken_down'])]]);
        $q = Package::where('tenant_id', $tenant->id)
            ->when($request->input('filter.status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['images.file', 'departures.prices'])
            ->orderByRaw("CASE status WHEN 'published' THEN 0 WHEN 'draft' THEN 1 WHEN 'taken_down' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request);
        $counts = Package::where('tenant_id', $tenant->id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => $rows->map(fn ($p) => P::package($p))->values(), 'meta' => $meta + ['counts' => $counts]]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $this->validated($request, true);
        $pkg = Package::create($data + ['tenant_id' => $tenant->id, 'slug' => $this->uniqueSlug($tenant, $data['name']), 'status' => 'draft']);
        Audit::log('package.created', $pkg, null, ['name' => $pkg->name], tenantId: $tenant->id, context: 'tenant');

        return $this->respond($pkg->fresh(), 201);
    }

    public function show(Tenant $tenant, string $package): JsonResponse
    {
        return $this->respond($this->find($tenant, $package));
    }

    public function update(Request $request, Tenant $tenant, string $package): JsonResponse
    {
        $pkg = $this->find($tenant, $package);
        $this->assertEditable($pkg);
        Etag::require($request, P::packageInput($pkg) + ['status' => $pkg->status]);
        $data = $this->validated($request, false);
        $old = array_intersect_key(P::packageInput($pkg), $data);
        $pkg->update($data);
        Audit::log('package.updated', $pkg, $old, $data, tenantId: $tenant->id, context: 'tenant');

        return $this->respond($pkg->fresh());
    }

    public function duplicate(Tenant $tenant, string $package): JsonResponse
    {
        $src = $this->find($tenant, $package);
        $copy = DB::transaction(function () use ($src, $tenant) {
            $name = Str::limit($src->name.' (salinan)', 200, '');
            $copy = Package::create(array_merge(P::packageInput($src), ['name' => $name, 'tenant_id' => $tenant->id, 'slug' => $this->uniqueSlug($tenant, $name), 'status' => 'draft']));
            foreach ($src->images as $img) {
                PackageImage::create(['tenant_id' => $tenant->id, 'package_id' => $copy->id, 'file_id' => $img->file_id, 'sort_order' => $img->sort_order]);
            }
            Audit::log('package.duplicated', $copy, null, ['from' => $src->id], tenantId: $tenant->id, context: 'tenant');

            return $copy;
        });

        return $this->respond($copy->fresh(), 201);
    }

    public function archive(Tenant $tenant, string $package): JsonResponse
    {
        $pkg = $this->find($tenant, $package);
        if (! in_array($pkg->status, ['draft', 'published'], true)) {
            throw ApiException::conflict('invalid_status_transition', 'Paket ini tidak bisa diarsipkan.');
        }
        return $this->transition($pkg, 'archived', 'package.archived');
    }

    public function publish(Tenant $tenant, string $package): JsonResponse
    {
        $pkg = $this->find($tenant, $package);
        if ($tenant->verification_status !== 'verified') {
            throw new ApiException(403, 'tenant_not_verified', 'Paket baru bisa diterbitkan setelah PPIU Anda terverifikasi.');
        }
        if (! in_array($pkg->status, ['draft', 'archived'], true)) {
            throw ApiException::conflict('invalid_status_transition', $pkg->status === 'taken_down'
                ? 'Paket ini diturunkan oleh tim UMRAH CONNECT. Hubungi kami untuk menerbitkan ulang.'
                : 'Paket sudah terbit.');
        }
        $check = P::publishChecklist($pkg);
        if (! $check['complete']) {
            throw ApiException::unprocessable('package_incomplete', 'Lengkapi data paket sebelum terbit.', ['checklist' => $check]);
        }
        $pkg->update(['status' => 'published', 'published_at' => $pkg->published_at ?? now()]);
        Audit::log('package.published', $pkg, null, ['status' => 'published'], tenantId: $tenant->id, context: 'tenant');

        return $this->respond($pkg->fresh());
    }

    /** Tarik dari marketplace → kembali draf; booking yang sudah ada tetap berjalan. */
    public function unpublish(Tenant $tenant, string $package): JsonResponse
    {
        $pkg = $this->find($tenant, $package);
        if ($pkg->status !== 'published') {
            throw ApiException::conflict('invalid_status_transition', 'Paket ini tidak sedang terbit.');
        }

        return $this->transition($pkg, 'draft', 'package.unpublished');
    }

    public function addImage(Request $request, Tenant $tenant, string $package): JsonResponse
    {
        $pkg = $this->find($tenant, $package);
        $this->assertEditable($pkg);
        $data = $request->validate(['file_id' => ['required', 'string']]);
        if ($pkg->images()->count() >= self::MAX_IMAGES) {
            throw ApiException::unprocessable('too_many_images', 'Maksimal '.self::MAX_IMAGES.' foto per paket. Hapus foto lama dulu.');
        }
        $file = FileController::claim($request, $data['file_id'], 'package_image', $tenant->id);
        PackageImage::create([
            'tenant_id' => $tenant->id, 'package_id' => $pkg->id, 'file_id' => $file->id,
            'sort_order' => (int) $pkg->images()->max('sort_order') + 1,
        ]);
        $pkg->touch();

        return $this->respond($pkg->fresh(), 201);
    }

    public function deleteImage(Tenant $tenant, string $package, string $image): Response
    {
        $pkg = $this->find($tenant, $package);
        $this->assertEditable($pkg);
        PackageImage::where('tenant_id', $tenant->id)->where('package_id', $pkg->id)->whereKey($image)->firstOrFail()->delete();
        $pkg->touch();

        return response()->noContent();
    }

    public function reorderImages(Request $request, Tenant $tenant, string $package): JsonResponse
    {
        $pkg = $this->find($tenant, $package);
        $data = $request->validate(['image_ids' => ['required', 'array'], 'image_ids.*' => ['uuid']]);
        $current = $pkg->images()->pluck('id')->all();
        if (count($data['image_ids']) !== count($current) || array_diff($current, $data['image_ids'])) {
            throw ApiException::unprocessable('image_ids_mismatch', 'Kirim semua foto paket ini tepat satu kali.');
        }
        DB::transaction(function () use ($data, $pkg) {
            foreach ($data['image_ids'] as $i => $id) {
                PackageImage::where('package_id', $pkg->id)->whereKey($id)->update(['sort_order' => $i]);
            }
        });
        $pkg->touch();

        return $this->respond($pkg->fresh());
    }

    private function respond(Package $pkg, int $status = 200): JsonResponse
    {
        return response()->json(['data' => P::package($pkg, true)], $status)->header('ETag', P::packageEtag($pkg));
    }

    private function transition(Package $pkg, string $to, string $action): JsonResponse
    {
        $from = $pkg->status;
        $pkg->update(['status' => $to]);
        Audit::log($action, $pkg, ['status' => $from], ['status' => $to], tenantId: $pkg->tenant_id, context: 'tenant');

        return $this->respond($pkg->fresh());
    }

    private function find(Tenant $tenant, string $id): Package
    {
        return Package::where('tenant_id', $tenant->id)->whereKey($id)->with(['images.file', 'departures.prices'])->firstOrFail();
    }

    private function assertEditable(Package $pkg): void
    {
        if (in_array($pkg->status, ['archived', 'taken_down'], true)) {
            throw ApiException::conflict('package_not_editable', $pkg->status === 'archived'
                ? 'Paket diarsipkan. Salin paket ini untuk membuat versi baru.'
                : 'Paket diturunkan oleh tim UMRAH CONNECT dan tidak bisa diubah.');
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$req, 'string', 'min:5', 'max:200'],
            'duration_days' => [$req, 'integer', 'min:1', 'max:60'],
            'airline' => [$req, 'string', 'max:100'],
            'hotel_makkah' => ['nullable', 'string', 'max:150'],
            'hotel_makkah_stars' => ['nullable', 'integer', 'between:1,5'],
            'hotel_makkah_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'hotel_madinah' => ['nullable', 'string', 'max:150'],
            'hotel_madinah_stars' => ['nullable', 'integer', 'between:1,5'],
            'hotel_madinah_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'itinerary' => ['sometimes', 'array', 'max:60'],
            'itinerary.*.day' => ['required', 'integer', 'min:1', 'max:60'],
            'itinerary.*.title' => ['required', 'string', 'max:150'],
            'itinerary.*.activities' => ['sometimes', 'array', 'max:20'],
            'itinerary.*.activities.*' => ['nullable', 'string', 'max:300'],
            'facilities' => ['sometimes', 'array', 'max:40'], 'facilities.*' => ['nullable', 'string', 'max:200'],
            'includes' => ['sometimes', 'array', 'max:40'], 'includes.*' => ['nullable', 'string', 'max:200'],
            'excludes' => ['sometimes', 'array', 'max:40'], 'excludes.*' => ['nullable', 'string', 'max:200'],
            'document_requirements' => ['nullable', 'string', 'max:3000'],
            'payment_terms' => ['nullable', 'string', 'max:3000'],
            'cancellation_terms' => ['nullable', 'string', 'max:3000'],
        ], [
            'name.min' => 'Nama paket minimal 5 karakter.',
            'itinerary.*.title.required' => 'Setiap hari di rencana perjalanan perlu judul.',
        ]);
        // Baris kosong dari form (menjadi null lewat ConvertEmptyStringsToNull) dibuang.
        $clean = fn (array $xs) => array_values(array_filter(array_map(fn ($x) => trim((string) $x), $xs), 'strlen'));
        foreach (['facilities', 'includes', 'excludes'] as $list) {
            if (isset($data[$list])) {
                $data[$list] = $clean($data[$list]);
            }
        }
        if (isset($data['itinerary'])) {
            $data['itinerary'] = collect($data['itinerary'])->sortBy('day')
                ->map(fn ($d) => ['day' => (int) $d['day'], 'title' => trim($d['title']), 'activities' => $clean($d['activities'] ?? [])])->values()->all();
        }

        return $data;
    }

    private function uniqueSlug(Tenant $tenant, string $name): string
    {
        $base = Str::limit(Str::slug($name), 140, '') ?: 'paket';
        $slug = $base;
        for ($i = 2; Package::withTrashed()->where('tenant_id', $tenant->id)->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
