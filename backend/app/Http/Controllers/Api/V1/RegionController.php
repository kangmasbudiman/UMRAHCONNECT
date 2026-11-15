<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Regions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar wilayah untuk dropdown alamat bertingkat (publik, data statis, boleh di-cache browser/CDN). */
class RegionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent' => ['nullable', 'string', 'regex:/^\d{2}(\.\d{2}(\.\d{2})?)?$/'],
        ], ['parent.regex' => 'Kode wilayah induk tidak valid.']);

        return response()->json(['data' => Regions::children($data['parent'] ?? null)])
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
