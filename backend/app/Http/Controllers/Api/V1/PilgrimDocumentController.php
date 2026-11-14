<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Pilgrim;
use App\Models\PilgrimDocument;
use App\Models\Tenant;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Dokumen jamaah (FR-JMH-06): dokumen baru sejenis menggantikan yang lama (status replaced). */
class PilgrimDocumentController extends Controller
{
    public static function present(PilgrimDocument $d, ?string $onlyTenantId = null): array
    {
        $reviews = $d->reviews->when($onlyTenantId, fn ($c) => $c->where('tenant_id', $onlyTenantId));
        $brands = Tenant::whereIn('id', $reviews->pluck('tenant_id'))->pluck('brand_name', 'id');

        return [
            'id' => $d->id, 'pilgrim_id' => $d->pilgrim_id, 'type' => $d->type, 'file_id' => $d->file_id,
            'file_name' => $d->file?->original_name, 'status' => $d->status, 'created_at' => $d->created_at?->toIso8601String(),
            'reviews' => $reviews->map(fn ($r) => ['tenant_id' => $r->tenant_id, 'tenant_name' => $brands[$r->tenant_id] ?? null, 'status' => $r->status, 'note' => $r->note, 'reviewed_at' => $r->reviewed_at?->toIso8601String()])->values(),
        ];
    }

    public function index(Request $request, string $pilgrim): JsonResponse
    {
        $p = Pilgrim::where('managed_by_user_id', $request->user()->id)->whereKey($pilgrim)->firstOrFail();
        $docs = PilgrimDocument::with(['file', 'reviews'])->where('pilgrim_id', $p->id)->where('status', 'active')->orderBy('type')->get();

        return response()->json(['data' => $docs->map(fn ($d) => self::present($d))->values()]);
    }

    public function store(Request $request, string $pilgrim): JsonResponse
    {
        $p = Pilgrim::where('managed_by_user_id', $request->user()->id)->whereKey($pilgrim)->firstOrFail();
        $data = $request->validate(['type' => ['required', Rule::in(['ktp', 'passport', 'photo', 'vaccine', 'other'])], 'file_id' => ['required', 'uuid']]);
        $file = FileController::claimPersonal($request, $data['file_id'], 'pilgrim_document');
        $doc = DB::transaction(function () use ($p, $data, $file, $request) {
            if ($data['type'] !== 'other') {
                PilgrimDocument::where('pilgrim_id', $p->id)->where('type', $data['type'])->where('status', 'active')->update(['status' => 'replaced', 'updated_at' => now()]);
            }

            return PilgrimDocument::create(['pilgrim_id' => $p->id, 'type' => $data['type'], 'file_id' => $file->id, 'status' => 'active', 'uploaded_by' => $request->user()->id]);
        });
        Audit::log('pilgrim_document.uploaded', $doc, null, ['type' => $doc->type], context: 'pilgrim');

        return response()->json(['data' => self::present($doc->load(['file', 'reviews']))], 201);
    }
}
