'use client';

import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef, useState } from 'react';
import { MAP_STYLE_URL, type Point } from './map-config';
import { loadMaplibre } from './load-maplibre';

/** Peta baca-saja dengan satu pin (profil PPIU publik). */
export function MapView({ point, label }: { point: Point; label: string }) {
  const box = useRef<HTMLDivElement>(null);
  const [ready, setReady] = useState(false);
  useEffect(() => {
    let m: import('maplibre-gl').Map | null = null;
    let cancelled = false;
    (async () => {
      const ml = await loadMaplibre();
      if (cancelled || !box.current) return;
      m = new ml.Map({ container: box.current, style: MAP_STYLE_URL, center: [point.lng, point.lat], zoom: 15, attributionControl: { compact: true }, cooperativeGestures: true });
      m.addControl(new ml.NavigationControl({ showCompass: false }), 'top-right');
      new ml.Marker({ color: '#0F5E4A' }).setLngLat([point.lng, point.lat]).setPopup(new ml.Popup({ offset: 24 }).setText(label)).addTo(m);
      m.on('load', () => setReady(true));
    })();
    return () => { cancelled = true; m?.remove(); };
  }, [point.lat, point.lng, label]);

  return (
    <div className="relative overflow-hidden rounded-[12px] border border-border-subtle">
      <div ref={box} className="h-64 w-full bg-primary-50" role="img" aria-label={`Peta lokasi ${label}`} />
      {!ready && <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-ink-600">Memuat peta…</div>}
    </div>
  );
}
