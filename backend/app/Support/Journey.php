<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\DepartureMilestone;
use App\Models\PilgrimDocument;

/**
 * "Lacak Umrah": linimasa booking dari dibuat sampai pulang. Sebagian tahap dihitung dari data booking
 * (DP, lunas, dokumen, berangkat), sebagian diperbarui PPIU per keberangkatan (visa, tiket, hotel, manasik).
 * Tidak memuat data pribadi, sehingga aman dipakai juga di halaman lacak publik.
 */
class Journey
{
    /** Dokumen wajib per jamaah agar tahap "Dokumen lengkap" selesai. */
    public const REQUIRED_DOCS = ['passport', 'photo', 'vaccine'];

    /** @return array{state: string, progress: int, steps: list<array{key:string,label:string,status:string,date:?string,note:?string}>} */
    public static function forBooking(Booking $b): array
    {
        $dep = $b->departure;
        $st = $b->status;
        $ended = in_array($st, ['expired', 'cancelled'], true);
        $milestones = DepartureMilestone::withoutTenantScope()->where('departure_id', $dep->id)->get()->keyBy('type');
        $paidStates = ['dp_verified', 'fully_paid', 'departed', 'completed'];

        $steps = [];
        $add = function (string $key, string $label, bool $done, ?string $date = null, ?string $note = null) use (&$steps) {
            $steps[] = ['key' => $key, 'label' => $label, 'status' => $done ? 'done' : 'pending', 'date' => $date, 'note' => $note];
        };

        $add('booked', 'Booking dibuat', true, $b->created_at?->setTimezone('Asia/Jakarta')->toDateString());
        $add('dp', 'DP diterima PPIU, kursi terkonfirmasi', in_array($st, $paidStates, true), $b->dp_verified_at?->setTimezone('Asia/Jakarta')->toDateString());
        $add('documents', 'Dokumen jamaah lengkap', self::documentsComplete($b), null, 'Paspor, pas foto, dan kartu vaksin meningitis setiap jamaah.');
        $add('paid', 'Lunas', in_array($st, ['fully_paid', 'departed', 'completed'], true), $b->fully_paid_at?->setTimezone('Asia/Jakarta')->toDateString());
        foreach (DepartureMilestone::TYPES as $type => $label) {
            $m = $milestones[$type] ?? null;
            $add($type, $label, $m?->status === 'done', $m?->happened_on?->toDateString(), $m?->note);
        }
        $add('departed', 'Berangkat', in_array($st, ['departed', 'completed'], true) || $dep->status === 'departed' || $dep->status === 'completed', $dep->departure_date->toDateString(), $dep->departure_city ? "Dari {$dep->departure_city}" : null);
        $add('returned', 'Pulang ke tanah air', $st === 'completed' || $dep->status === 'completed', $dep->return_date->toDateString());

        // Tahap yang tidak relevan setelah booking berakhir ditandai skipped.
        if ($ended) {
            foreach ($steps as &$s) {
                if ($s['status'] === 'pending') {
                    $s['status'] = 'skipped';
                }
            }
            unset($s);
        }
        $done = count(array_filter($steps, fn ($s) => $s['status'] === 'done'));
        // Tahap berikutnya yang sedang ditunggu ditandai "current" agar mudah dibaca.
        if (! $ended) {
            foreach ($steps as &$s) {
                if ($s['status'] === 'pending') {
                    $s['status'] = 'current';
                    break;
                }
            }
            unset($s);
        }

        return [
            'state' => $ended ? $st : ($st === 'completed' ? 'completed' : 'active'),
            'progress' => (int) round($done / count($steps) * 100),
            'steps' => $steps,
        ];
    }

    private static function documentsComplete(Booking $b): bool
    {
        $pilgrims = BookingParticipant::withoutTenantScope()->where('booking_id', $b->id)->where('is_active', true)->pluck('pilgrim_id');
        if ($pilgrims->isEmpty()) {
            return false;
        }
        $have = PilgrimDocument::query()->whereIn('pilgrim_id', $pilgrims)->where('status', 'active')->whereIn('type', self::REQUIRED_DOCS)
            ->selectRaw('pilgrim_id, count(distinct type) c')->groupBy('pilgrim_id')->pluck('c', 'pilgrim_id');

        return $pilgrims->every(fn ($id) => (int) ($have[$id] ?? 0) === count(self::REQUIRED_DOCS));
    }
}
