'use client';

import 'maplibre-gl/dist/maplibre-gl.css';
import type { Map as MlMap, Marker } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { ID_CENTER, MAP_STYLE_URL, type Point } from './map-config';
import { loadMaplibre } from './load-maplibre';

export type PpiuPin = { slug: string; name: string; city: string | null; lat: number; lng: number; distance_km?: number };

const fmtKm = (km: number) => `${km.toLocaleString('id-ID', { maximumFractionDigits: 1 })} km`;

/** Isi popup dibuat lewat DOM (bukan HTML string) agar nama PPIU tidak bisa menyisipkan markup. */
function popupContent(p: PpiuPin): HTMLElement {
  const el = document.createElement('div');
  el.className = 'ppiu-popup';
  const name = document.createElement('p');
  name.className = 'font-bold text-ink-900';
  name.textContent = p.name;
  const meta = document.createElement('p');
  meta.className = 'text-xs text-ink-600';
  meta.textContent = [p.city, p.distance_km !== undefined ? `± ${fmtKm(p.distance_km)} dari Anda` : null].filter(Boolean).join(' · ');
  const link = document.createElement('a');
  link.href = `/ppiu/${encodeURIComponent(p.slug)}`;
  link.className = 'mt-1 inline-block text-sm font-bold text-primary-700 underline';
  link.textContent = 'Lihat profil';
  el.append(name, meta, link);
  return el;
}

/** Peta semua PPIU di hasil pencarian (direktori /ppiu). `me` = titik perkiraan pengguna saat "Cari PPIU terdekat". */
export function PpiuMap({ pins, me = null, className = 'h-72 lg:h-[calc(100vh-7rem)]' }: { pins: PpiuPin[]; me?: Point | null; className?: string }) {
  const box = useRef<HTMLDivElement>(null);
  const map = useRef<MlMap | null>(null);
  const ml = useRef<Awaited<ReturnType<typeof loadMaplibre>> | null>(null);
  const markers = useRef<Marker[]>([]);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const lib = await loadMaplibre();
      if (cancelled || !box.current) return;
      const m = new lib.Map({ container: box.current, style: MAP_STYLE_URL, center: ID_CENTER, zoom: 3.6, attributionControl: { compact: true }, cooperativeGestures: true });
      m.addControl(new lib.NavigationControl({ showCompass: false }), 'top-right');
      ml.current = lib;
      map.current = m;
      m.on('load', () => setReady(true));
    })();
    return () => { cancelled = true; map.current?.remove(); map.current = null; };
  }, []);

  // Pin diperbarui tiap hasil berubah (filter lokasi / titik terdekat) tanpa membuat ulang peta.
  const sig = JSON.stringify([pins.map((p) => [p.slug, p.lat, p.lng, p.distance_km]), me]);
  useEffect(() => {
    const m = map.current;
    const lib = ml.current;
    if (!ready || !m || !lib) return;
    markers.current.forEach((mk) => mk.remove());
    markers.current = pins.map((p) =>
      new lib.Marker({ color: '#0F5E4A' })
        .setLngLat([p.lng, p.lat])
        .setPopup(new lib.Popup({ offset: 24, closeButton: false, maxWidth: '240px' }).setDOMContent(popupContent(p)))
        .addTo(m),
    );
    if (me) {
      const dot = document.createElement('div');
      dot.className = 'h-4 w-4 rounded-full border-2 border-white bg-[#2563EB] shadow-[0_0_0_6px_rgba(37,99,235,0.25)]';
      dot.setAttribute('aria-label', 'Perkiraan lokasi Anda');
      markers.current.push(new lib.Marker({ element: dot }).setLngLat([me.lng, me.lat]).addTo(m));
    }
    const all = [...pins.map((p) => [p.lng, p.lat] as [number, number]), ...(me ? [[me.lng, me.lat] as [number, number]] : [])];
    if (all.length === 1) m.jumpTo({ center: all[0], zoom: 13 });
    else if (all.length > 1) {
      const b = new lib.LngLatBounds(all[0], all[0]);
      all.forEach((c) => b.extend(c));
      m.fitBounds(b, { padding: 56, maxZoom: 13, duration: 0 });
    } else m.jumpTo({ center: ID_CENTER, zoom: 3.6 });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- dipicu oleh isi hasil (sig), bukan identitas array
  }, [sig, ready]);

  return (
    <div className="relative overflow-hidden rounded-[12px] border border-border-subtle">
      <div ref={box} className={`${className} w-full bg-primary-50`} role="region" aria-label={`Peta ${pins.length} PPIU`} />
      {!ready && <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-ink-600">Memuat peta…</div>}
    </div>
  );
}
