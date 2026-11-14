<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\OffsetCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Pusat notifikasi (FR-NTF-01): hanya kanal in_app yang ditampilkan. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Notification::where('user_id', $request->user()->id)->where('channel', 'in_app')
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id');
        [$rows, $meta] = OffsetCursor::paginate($q, $request, 30, 100);
        $unread = Notification::where('user_id', $request->user()->id)->where('channel', 'in_app')->whereNull('read_at')->count();

        return response()->json(['data' => $rows->map(fn ($n) => [
            'id' => $n->id, 'event' => $n->event, 'channel' => $n->channel, 'payload' => $n->payload,
            'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String(),
        ])->values(), 'meta' => $meta + ['unread' => $unread]]);
    }

    public function read(Request $request): Response
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['uuid']]);
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }
}
