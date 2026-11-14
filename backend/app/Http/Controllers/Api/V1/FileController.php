<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\TenantMember;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Unggah file (API §Files). Tipe dicek dari isi file; file privat diakses lewat signed URL 10 menit. */
class FileController extends Controller
{
    /** purpose => [visibility, max KB, mime yang diizinkan]. Sprint 1: dokumen PPIU dan logo. */
    private const RULES = [
        'tenant_document' => ['private', 5120, ['image/jpeg', 'image/png', 'application/pdf']],
        'logo' => ['public', 2048, ['image/jpeg', 'image/png', 'image/webp']],
    ];

    public function store(Request $request): JsonResponse
    {
        $request->validate(['purpose' => ['required', Rule::in(array_keys(self::RULES))]], [
            'purpose.in' => 'Jenis unggahan ini belum didukung.',
        ]);
        [$visibility, $maxKb, $mimes] = self::RULES[$request->input('purpose')];
        $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $mimes)],
        ], [
            'file.max' => 'Ukuran file maksimal '.($maxKb / 1024).' MB.',
            'file.mimetypes' => 'Format file harus '.implode(', ', array_map(fn ($m) => strtoupper(Str::after($m, '/')), $mimes)).'.',
        ]);

        $upload = $request->file('file');
        $mime = $upload->getMimeType(); // dari isi file (finfo), bukan dari nama
        $disk = $visibility === 'public' ? 'public' : 'local';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime];
        $path = $upload->storeAs('files/'.now()->format('Y/m'), Str::uuid().'.'.$ext, $disk);

        $file = File::create([
            'uploaded_by' => $request->user()->id,
            'visibility' => $visibility,
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($upload->getClientOriginalName(), 250, ''),
            'mime_type' => $mime,
            'size_bytes' => $upload->getSize(),
            'checksum_sha256' => hash_file('sha256', $upload->getRealPath()),
            'purpose' => $request->input('purpose'),
        ]);

        return response()->json(['data' => self::present($file)], 201);
    }

    public function show(Request $request, string $file): JsonResponse
    {
        $f = $this->authorizedFile($request, $file);

        return response()->json(['data' => self::present($f)]);
    }

    /** Isi file lewat signed URL relatif (bekerja juga lewat proxy Next.js di domain web). */
    public function content(string $file)
    {
        $f = File::findOrFail($file);

        return Storage::disk($f->disk)->response($f->path, $f->original_name, [
            'Content-Type' => $f->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($f->original_name ?? 'file').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public static function present(File $f): array
    {
        $url = $f->visibility === 'public'
            ? Storage::disk($f->disk)->url($f->path)
            : URL::temporarySignedRoute('files.content', now()->addMinutes(10), ['file' => $f->id], absolute: false);

        return [
            'id' => $f->id,
            'purpose' => $f->purpose,
            'mime_type' => $f->mime_type,
            'size_bytes' => $f->size_bytes,
            'original_name' => $f->original_name,
            'url' => $url,
        ];
    }

    /** Pengunggah, anggota aktif tenant pemilik file, atau staf platform. Selain itu 404. */
    private function authorizedFile(Request $request, string $id): File
    {
        if (! Str::isUuid($id)) {
            throw new NotFoundHttpException;
        }
        $f = File::findOrFail($id);
        $user = $request->user();
        $ok = $f->uploaded_by === $user->id
            || ($f->tenant_id && TenantMember::withoutTenantScope()->where('tenant_id', $f->tenant_id)->where('user_id', $user->id)->where('status', 'active')->exists())
            || Permissions::platformAllows($user, 'platform.tenant.view');
        if (! $ok) {
            throw new NotFoundHttpException;
        }

        return $f;
    }

    /** Dipakai endpoint lain: file harus milik pengunggah, purpose cocok, dan belum dipakai tenant lain. */
    public static function claim(Request $request, string $fileId, string $purpose, string $tenantId): File
    {
        $f = Str::isUuid($fileId) ? File::find($fileId) : null;
        if (! $f || $f->uploaded_by !== $request->user()->id || $f->purpose !== $purpose || ($f->tenant_id && $f->tenant_id !== $tenantId)) {
            throw new ApiException(422, 'file_invalid', 'File tidak ditemukan atau bukan unggahan Anda. Unggah ulang dokumennya.', errors: ['file_id' => ['File tidak valid.']]);
        }
        $f->forceFill(['tenant_id' => $tenantId])->save();

        return $f;
    }
}
