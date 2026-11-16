'use client';

import 'maplibre-gl/dist/maplibre-gl.css';
import type { Map as MlMap, Marker } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import { IconMapPin, IconX } from '@/components/icons';
import { geocodeArea, ID_CENTER, inIndonesia, MAP_STYLE_URL, type Point } from './map-config';
import { loadMaplibre } from './load-maplibre';

const fmt = (n: number) => n.toFixed(6);

/**
 * Pilih titik lokasi di peta: klik peta / geser pin / "Pakai lokasi saya" / isi koordinat manual.
 * Mengirim field `latitude` dan `longitude`. `focus` (nama wilayah) mengarahkan peta saat alamat dipilih.
 */
export function MapPicker({
  initial = null, focus = [], required = true, error, idPrefix = 'map', disabled = false,
}: {
  initial?: Point | null; focus?: string[]; required?: boolean; error?: string; idPrefix?: string; disabled?: boolean;
}) {
  const box = useRef<HTMLDivElement>(null);
  const map = useRef<MlMap | null>(null);
  const marker = useRef<Marker | null>(null);
  const [point, setPoint] = useState<Point | null>(initial);
  const [lat, setLat] = useState(initial ? fmt(initial.lat) : '');
  const [lng, setLng] = useState(initial ? fmt(initial.lng) : '');
  const [note, setNote] = useState<string | null>(null);
  const [locating, setLocating] = useState(false);
  const [ready, setReady] = useState(false);

  function place(p: Point, opts: { fly?: boolean } = {}) {
    if (!inIndonesia(p)) { setNote('Titik berada di luar wilayah Indonesia.'); return; }
    setNote(null);
    setPoint(p);
    setLat(fmt(p.lat));
    setLng(fmt(p.lng));
    marker.current?.setLngLat([p.lng, p.lat]).addTo(map.current!);
    if (opts.fly) map.current?.flyTo({ center: [p.lng, p.lat], zoom: Math.max(map.current.getZoom(), 16) });
  }
  // Handler peta (klik/geser) dipasang sekali; ref ini selalu menunjuk `place` terbaru.
  const placeRef = useRef(place);
  useEffect(() => { placeRef.current = place; });

  // Peta dibuat sekali di browser (maplibre-gl diimpor dinamis agar tidak ikut render server).
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const ml = await loadMaplibre();
      if (cancelled || !box.current) return;
      const m = new ml.Map({
        container: box.current,
        style: MAP_STYLE_URL,
        center: initial ? [initial.lng, initial.lat] : ID_CENTER,
        zoom: initial ? 16 : 3.6,
        attributionControl: { compact: true },
        cooperativeGestures: true, // gulir halaman tidak terjebak di peta (ponsel)
      });
      m.addControl(new ml.NavigationControl({ showCompass: false }), 'top-right');
      const mk = new ml.Marker({ color: '#0F5E4A', draggable: !disabled });
      mk.on('dragend', () => { const ll = mk.getLngLat(); placeRef.current({ lat: ll.lat, lng: ll.lng }); });
      if (initial) mk.setLngLat([initial.lng, initial.lat]).addTo(m);
      if (!disabled) m.on('click', (e) => placeRef.current({ lat: e.lngLat.lat, lng: e.lngLat.lng }));
      map.current = m;
      marker.current = mk;
      m.on('load', () => setReady(true));
    })();
    return () => { cancelled = true; map.current?.remove(); map.current = null; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- peta cukup dibuat sekali
  }, []);

  // Saat kelurahan dipilih: arahkan peta ke wilayah itu (titik belum diisi; pengguna tetap menandai sendiri).
  const focusKey = focus.join('|');
  useEffect(() => {
    // Hanya di tingkat kab/kota (2) dan kelurahan (4), dengan jeda, agar patuh batas 1 permintaan/detik Nominatim.
    if (point || (focus.length !== 2 && focus.length !== 4)) return;
    const ctl = new AbortController();
    const timer = setTimeout(() => {
      geocodeArea(focus, ctl.signal).then((p) => { if (p) map.current?.flyTo({ center: [p.lng, p.lat], zoom: focus.length >= 4 ? 15 : 12 }); });
    }, 800);
    return () => { clearTimeout(timer); ctl.abort(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- dipicu perubahan wilayah saja
  }, [focusKey]);

  function useMyLocation() {
    if (!navigator.geolocation) { setNote('Perangkat tidak mendukung lokasi otomatis.'); return; }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => { setLocating(false); place({ lat: pos.coords.latitude, lng: pos.coords.longitude }, { fly: true }); },
      () => { setLocating(false); setNote('Lokasi tidak bisa diambil. Izinkan akses lokasi di browser, atau klik peta.'); },
      { enableHighAccuracy: true, timeout: 15000 },
    );
  }

  function typed(nextLat: string, nextLng: string) {
    setLat(nextLat);
    setLng(nextLng);
    const p = { lat: Number(nextLat), lng: Number(nextLng) };
    if (nextLat.trim() && nextLng.trim() && Number.isFinite(p.lat) && Number.isFinite(p.lng)) place(p, { fly: true });
  }

  function clear() {
    setPoint(null); setLat(''); setLng(''); marker.current?.remove();
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 font-semibold"><IconMapPin className="h-5 w-5 text-primary-700" />Titik lokasi di peta{!required && <span className="font-normal text-ink-600"> (opsional)</span>}</p>
        {!disabled && (
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={useMyLocation} disabled={locating} className="btn btn-outline min-h-10 px-3 text-sm">
              {locating ? 'Mencari lokasi…' : 'Pakai lokasi saya'}
            </button>
            {point && <button type="button" onClick={clear} className="btn min-h-10 px-3 text-sm text-ink-600 hover:bg-surface"><IconX className="h-4 w-4" />Hapus titik</button>}
          </div>
        )}
      </div>
      <div className="relative overflow-hidden rounded-[12px] border border-border-input">
        <div ref={box} className="h-72 w-full bg-primary-50 md:h-80" role="application" aria-label="Peta untuk menandai lokasi. Klik peta atau geser pin." />
        {!ready && <div className="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-ink-600">Memuat peta…</div>}
      </div>
      <p className="text-sm text-ink-600">
        {disabled ? 'Lokasi kantor terdaftar.' : point ? 'Geser pin bila belum tepat.' : 'Klik peta pada lokasi kantor, atau tekan "Pakai lokasi saya" saat berada di kantor.'}
      </p>
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label htmlFor={`${idPrefix}-lat`} className="mb-1 block text-sm font-semibold">Latitude</label>
          <input id={`${idPrefix}-lat`} name="latitude" className="input num" inputMode="decimal" value={lat} placeholder="-1.610000"
            onChange={(e) => typed(e.target.value, lng)} disabled={disabled} required={required} aria-invalid={error ? true : undefined} />
        </div>
        <div>
          <label htmlFor={`${idPrefix}-lng`} className="mb-1 block text-sm font-semibold">Longitude</label>
          <input id={`${idPrefix}-lng`} name="longitude" className="input num" inputMode="decimal" value={lng} placeholder="103.610000"
            onChange={(e) => typed(lat, e.target.value)} disabled={disabled} required={required} aria-invalid={error ? true : undefined} />
        </div>
      </div>
      {(error || note) && <p role="alert" className="text-sm font-semibold text-danger">{error ?? note}</p>}
    </div>
  );
}
