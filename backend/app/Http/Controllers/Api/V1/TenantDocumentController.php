<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Support\Audit;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Dokumen legalitas PPIU (FR-PPIU-01). Riwayat disimpan; dokumen terbaru per jenis yang berlaku. */
class TenantDocumentController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $docs = TenantDocument::with('file')->where('tenant_id', $tenant->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => $docs->map(fn ($d) => Present::document($d) + ['file' => FileController::present($d->file)])->values()]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        if (! in_array($tenant->verification_status, ['draft', 'needs_revision', 'verified'], true)) {
            throw ApiException::conflict('tenant_not_editable', $tenant->verification_status === 'pending_review'
                ? 'Dokumen sedang ditinjau. Tunggu hasil verifikasi sebelum mengunggah ulang.'
                : 'Dokumen tidak bisa diunggah untuk PPIU dengan status ini.');
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(['akta', 'nib', 'npwp', 'ppiu_license', 'pic_ktp', 'other'])],
            'file_id' => ['required', 'string'],
        ]);
        $file = FileController::claim($request, $data['file_id'], 'tenant_document', $tenant->id);
        if (TenantDocument::where('file_id', $file->id)->exists()) {
            throw ApiException::unprocessable('file_invalid', 'File ini sudah dipakai untuk dokumen lain.');
        }
        $doc = TenantDocument::create(['tenant_id' => $tenant->id, 'type' => $data['type'], 'file_id' => $file->id, 'status' => 'submitted']);
        Audit::log('tenant_document.uploaded', $doc, null, ['type' => $doc->type], tenantId: $tenant->id, context: 'tenant');

        return response()->json(['data' => Present::document($doc->load('file')) + ['file' => FileController::present($file)]], 201);
    }
}
