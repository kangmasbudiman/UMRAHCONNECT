<?php

namespace App\Support;

use App\Models\Departure;
use App\Models\Package;
use App\Models\PackageImage;
use Illuminate\Support\Facades\Storage;

/** Bentuk respons Package, PackageImage, Departure (api/openapi.yaml) untuk konteks PPIU. */
class CatalogPresenter
{
    private const ROOMS = ['quad', 'triple', 'double'];

    public static function image(PackageImage $i): array
    {
        return [
            'id' => $i->id,
            'file_id' => $i->file_id,
            'url' => $i->file ? Storage::disk($i->file->disk)->url($i->file->path) : null,
            'sort_order' => $i->sort_order,
        ];
    }

    /** Field yang bisa diubah PPIU — juga dasar ETag paket. */
    public static function packageInput(Package $p): array
    {
        return [
            'name' => $p->name, 'duration_days' => $p->duration_days, 'airline' => $p->airline,
            'hotel_makkah' => $p->hotel_makkah, 'hotel_makkah_stars' => $p->hotel_makkah_stars, 'hotel_makkah_distance_m' => $p->hotel_makkah_distance_m,
            'hotel_madinah' => $p->hotel_madinah, 'hotel_madinah_stars' => $p->hotel_madinah_stars, 'hotel_madinah_distance_m' => $p->hotel_madinah_distance_m,
            'description' => $p->description, 'itinerary' => $p->itinerary ?? [], 'facilities' => $p->facilities ?? [],
            'includes' => $p->includes ?? [], 'excludes' => $p->excludes ?? [],
            'document_requirements' => $p->document_requirements, 'payment_terms' => $p->payment_terms, 'cancellation_terms' => $p->cancellation_terms,
        ];
    }

    public static function package(Package $p, bool $withDepartures = false): array
    {
        $p->loadMissing(['images.file', 'departures.prices']);
        $upcoming = $p->departures->filter(fn (Departure $d) => $d->status === 'scheduled' && $d->departure_date->toDateString() >= now('Asia/Jakarta')->toDateString());
        $out = self::packageInput($p) + [
            'id' => $p->id,
            'slug' => $p->slug,
            'status' => $p->status,
            'taken_down_reason' => $p->taken_down_reason,
            'published_at' => $p->published_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
            'images' => $p->images->map(fn ($i) => self::image($i))->values(),
            // Ringkasan (tambahan) untuk daftar paket di dasbor
            'departures_count' => $upcoming->count(),
            'price_from_amount' => $upcoming->flatMap(fn ($d) => $d->prices)->min('price_amount'),
            'next_departure_date' => $upcoming->sortBy('departure_date')->first()?->departure_date->toDateString(),
            'etag' => self::packageEtag($p),
        ];
        if ($withDepartures) {
            $out['departures'] = $p->departures->sortBy('departure_date')->map(fn ($d) => self::departure($d))->values();
            $out['publish_checklist'] = self::publishChecklist($p);
        }

        return $out;
    }

    public static function packageEtag(Package $p): string
    {
        return Etag::of(self::packageInput($p) + ['status' => $p->status]);
    }

    public static function departureInput(Departure $d): array
    {
        return [
            'departure_date' => $d->departure_date->toDateString(),
            'return_date' => $d->return_date->toDateString(),
            'departure_city' => $d->departure_city,
            'quota' => $d->quota,
            'dp_min_per_pax_amount' => $d->dp_min_per_pax_amount,
            'dp_deadline_hours' => $d->dp_deadline_hours,
            'final_payment_due_on' => $d->final_payment_due_on->toDateString(),
            'prices' => $d->prices->sortBy(fn ($x) => array_search($x->room_type, self::ROOMS))
                ->map(fn ($x) => ['room_type' => $x->room_type, 'price_amount' => (int) $x->price_amount])->values()->all(),
        ];
    }

    public static function departureEtag(Departure $d): string
    {
        return Etag::of(self::departureInput($d) + ['status' => $d->status, 'sales_status' => $d->sales_status]);
    }

    public static function departure(Departure $d, ?int $activeBookings = null): array
    {
        $d->loadMissing('prices');
        $out = self::departureInput($d) + [
            'id' => $d->id,
            'package_id' => $d->package_id,
            'seats_held' => $d->seats_held,
            'seats_confirmed' => $d->seats_confirmed,
            'seats_remaining' => $d->seatsRemaining(),
            'sales_status' => $d->sales_status,
            'status' => $d->status,
            'etag' => self::departureEtag($d),
        ];
        if ($activeBookings !== null) {
            $out['active_bookings_count'] = $activeBookings;
        }

        return $out;
    }

    /** Syarat terbit (BP-02): data wajib lengkap dan minimal satu keberangkatan mendatang yang dibuka. */
    public static function publishChecklist(Package $p): array
    {
        $p->loadMissing(['departures.prices', 'images']);
        $today = now('Asia/Jakarta')->toDateString();
        $sellable = $p->departures->first(fn ($d) => $d->status === 'scheduled' && $d->sales_status !== 'closed'
            && $d->departure_date->toDateString() > $today && $d->prices->isNotEmpty());
        $items = [
            'description' => filled($p->description),
            'hotels' => filled($p->hotel_makkah) && filled($p->hotel_madinah),
            'itinerary' => count($p->itinerary ?? []) > 0,
            'includes' => count($p->includes ?? []) > 0,
            'payment_terms' => filled($p->payment_terms),
            'cancellation_terms' => filled($p->cancellation_terms),
            'departure' => $sellable !== null,
        ];

        return ['items' => $items, 'complete' => ! in_array(false, $items, true), 'has_images' => $p->images->isNotEmpty()];
    }

    /**
     * Skor transparansi paket (0–100): seberapa lengkap informasi yang dibutuhkan jamaah sebelum membayar.
     * Bobot mengikuti keluhan jamaah yang paling sering: rencana perjalanan, jarak hotel, kebijakan batal, biaya di luar paket.
     *
     * @return array{score:int, items:list<array{key:string,label:string,ok:bool,weight:int}>}
     */
    public static function transparency(Package $p): array
    {
        $p->loadMissing(['images', 'tenant']);
        $itinDays = count(array_filter($p->itinerary ?? [], fn ($d) => filled($d['title'] ?? null) && count($d['activities'] ?? []) > 0));
        $dep = $p->relationLoaded('departures') ? $p->departures->first() : null;
        $priced = $dep && $dep->relationLoaded('prices') ? $dep->prices->count() : 0;
        $items = [
            ['itinerary', 'Rencana perjalanan lengkap setiap hari', $p->duration_days > 0 && $itinDays >= $p->duration_days, 15],
            ['hotels', 'Nama dan bintang hotel Makkah & Madinah', filled($p->hotel_makkah) && filled($p->hotel_madinah) && $p->hotel_makkah_stars && $p->hotel_madinah_stars, 10],
            ['distance', 'Jarak hotel ke Masjidil Haram & Nabawi', $p->hotel_makkah_distance_m !== null && $p->hotel_madinah_distance_m !== null, 10],
            ['includes', 'Rincian termasuk & tidak termasuk', count($p->includes ?? []) > 0 && count($p->excludes ?? []) > 0, 10],
            ['facilities', 'Daftar fasilitas', count($p->facilities ?? []) > 0, 5],
            ['documents', 'Syarat dokumen', filled($p->document_requirements), 10],
            ['payment', 'Ketentuan pembayaran (DP & pelunasan)', filled($p->payment_terms), 10],
            ['cancellation', 'Kebijakan pembatalan & refund', filled($p->cancellation_terms), 15],
            ['photos', 'Minimal 3 foto', $p->images->count() >= 3, 5],
            ['prices', 'Harga per tipe kamar', $priced >= 2, 5],
            ['license', 'Izin PPIU masih berlaku', $p->tenant && $p->tenant->license_expires_on && $p->tenant->license_expires_on->toDateString() >= now('Asia/Jakarta')->toDateString(), 5],
        ];
        $score = array_sum(array_map(fn ($i) => $i[2] ? $i[3] : 0, $items));

        return ['score' => $score, 'items' => array_map(fn ($i) => ['key' => $i[0], 'label' => $i[1], 'ok' => (bool) $i[2], 'weight' => $i[3]], $items)];
    }

    /** Ramah lansia: kedua hotel paling jauh 300 m dari masjid. */
    public const ELDERLY_MAX_DISTANCE_M = 300;

    public static function elderlyFriendly(Package $p): bool
    {
        return $p->hotel_makkah_distance_m !== null && $p->hotel_madinah_distance_m !== null
            && $p->hotel_makkah_distance_m <= self::ELDERLY_MAX_DISTANCE_M && $p->hotel_madinah_distance_m <= self::ELDERLY_MAX_DISTANCE_M;
    }
}
