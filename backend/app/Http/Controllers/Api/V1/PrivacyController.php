<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AccountPrivacyService;
use App\Support\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Privasi & keamanan akun: unduh data, hapus akun (UU PDP), dan kelola perangkat yang sedang masuk. */
class PrivacyController extends Controller
{
    public function __construct(private AccountPrivacyService $privacy) {}

    public function export(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'two_factor_code' => ['nullable', 'string', 'max:20']], ['password.required' => 'Masukkan kata sandi Anda.']);
        $this->privacy->reauthenticate($request->user(), $data['password'], $data['two_factor_code'] ?? null);

        return response()->json($this->privacy->export($request->user()), 200, [
            'Content-Disposition' => 'attachment; filename="data-saya-umrah-connect-'.now('Asia/Jakarta')->format('Ymd').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function deletionCheck(Request $request): JsonResponse
    {
        return response()->json(['data' => ['blockers' => $this->privacy->blockers($request->user())]]);
    }

    public function delete(Request $request): Response
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
            'confirm' => ['required', 'in:HAPUS'],
        ], ['confirm.in' => 'Ketik HAPUS (huruf besar) untuk konfirmasi.', 'confirm.required' => 'Ketik HAPUS untuk konfirmasi.']);
        $user = $request->user();
        $this->privacy->reauthenticate($user, $data['password'], $data['two_factor_code'] ?? null);
        $this->privacy->delete($user);
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** Perangkat yang sedang masuk (sesi web). Kunci sesi asli tidak pernah dikirim; dipakai hash pendek. */
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;
        $rows = DB::table('sessions')->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return response()->json(['data' => $rows->map(fn ($s) => [
            'key' => substr(hash('sha256', $s->id), 0, 16),
            'device' => Device::label($s->user_agent),
            'ip_address' => $s->ip_address,
            'last_active_at' => now()->setTimestamp((int) $s->last_activity)->toIso8601String(),
            'current' => $s->id === $current,
        ])->values()]);
    }

    public function revokeSession(Request $request, string $key): Response
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;
        DB::table('sessions')->where('user_id', $request->user()->id)->get(['id'])
            ->filter(fn ($s) => $s->id !== $current && substr(hash('sha256', $s->id), 0, 16) === $key)
            ->each(fn ($s) => DB::table('sessions')->where('id', $s->id)->delete());

        return response()->noContent();
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $current = $request->hasSession() ? $request->session()->getId() : null;
        $n = DB::table('sessions')->where('user_id', $request->user()->id)->when($current, fn ($q) => $q->where('id', '!=', $current))->delete();
        $request->user()->tokens()->delete();

        return response()->json(['data' => ['revoked' => $n]]);
    }
}
