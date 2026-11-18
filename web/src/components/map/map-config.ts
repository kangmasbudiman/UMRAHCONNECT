/**
 * Konfigurasi peta (aman diimpor server; tidak memuat maplibre-gl).
 * Peta MapLibre GL dengan ubin vektor OpenFreeMap (gratis, tanpa kunci API).
 * Ganti lewat NEXT_PUBLIC_MAP_STYLE_URL bila nanti memakai server ubin sendiri / MapTiler.
 */
export const MAP_STYLE_URL = process.env.NEXT_PUBLIC_MAP_STYLE_URL ?? 'https://tiles.openfreemap.org/styles/liberty';

/** Batas wilayah Indonesia — sama dengan validasi server (App\Support\Geo). */
export const ID_BOUNDS = { lat: [-11.5, 6.5], lng: [94.5, 141.5] } as const;
export const ID_CENTER: [number, number] = [118, -2.5]; // [lng, lat]

export type Point = { lat: number; lng: number };

export const inIndonesia = (p: Point) => p.lat >= ID_BOUNDS.lat[0] && p.lat <= ID_BOUNDS.lat[1] && p.lng >= ID_BOUNDS.lng[0] && p.lng <= ID_BOUNDS.lng[1];

/** Pilihan radius "PPIU terdekat" (km); server membatasi maksimal 500. */
export const NEAR_RADII = [25, 50, 100, 250, 500] as const;
export const NEAR_DEFAULT_RADIUS = 50;

/**
 * Titik "dekat" dari URL ("-1.61,103.59") → Point, atau null bila format/rentang salah.
 * Dibulatkan 2 desimal (±1 km) di peramban sebelum masuk URL, agar lokasi persis pengguna tidak tersimpan di riwayat/log.
 */
export function parseNear(raw: string | undefined): Point | null {
  const m = raw?.match(/^(-?\d{1,2}(?:\.\d{1,4})?),(-?\d{1,3}(?:\.\d{1,4})?)$/);
  if (!m) return null;
  const p = { lat: Number(m[1]), lng: Number(m[2]) };
  return inIndonesia(p) ? p : null;
}

/** Tautan petunjuk arah (membuka aplikasi peta di ponsel). */
export const directionsUrl = (p: Point) => `https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}`;

/** Cari titik tengah wilayah dari nama (kelurahan, kecamatan, kab/kota, provinsi) via Nominatim OSM. Hanya untuk mengarahkan peta. */
export async function geocodeArea(parts: string[], signal?: AbortSignal): Promise<Point | null> {
  const tries = [parts, parts.slice(1), parts.slice(2)].filter((t) => t.length > 0);
  for (const [i, t] of tries.entries()) {
    // Kebijakan Nominatim: maksimal 1 permintaan/detik, jadi percobaan cadangan diberi jeda.
    if (i > 0) {
      await new Promise((r) => setTimeout(r, 1100));
      if (signal?.aborted) return null;
    }
    const q = encodeURIComponent([...t, 'Indonesia'].join(', '));
    try {
      const r = await fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=id&q=${q}`, { signal, headers: { Accept: 'application/json' } });
      if (!r.ok) continue;
      const [hit] = (await r.json()) as { lat: string; lon: string }[];
      if (hit) return { lat: Number(hit.lat), lng: Number(hit.lon) };
    } catch {
      if (signal?.aborted) return null;
    }
  }
  return null;
}
