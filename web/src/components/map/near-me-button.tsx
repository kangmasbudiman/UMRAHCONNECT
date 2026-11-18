'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { IconMapPin } from '@/components/icons';
import { inIndonesia } from './map-config';

/**
 * "Cari PPIU terdekat": ambil lokasi perangkat lalu buka /ppiu?dekat=lat,lng.
 * Koordinat dibulatkan 2 desimal (±1 km) sebelum masuk URL — cukup untuk mengurutkan jarak,
 * tanpa menyimpan lokasi persis pengguna di riwayat peramban atau log server.
 */
export function NearMeButton({ radius, className = 'btn btn-outline' }: { radius?: number; className?: string }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);

  function locate() {
    if (!navigator.geolocation) { setNote('Perangkat ini tidak mendukung lokasi otomatis. Pilih lokasi dari daftar.'); return; }
    setBusy(true);
    setNote(null);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const p = { lat: Number(pos.coords.latitude.toFixed(2)), lng: Number(pos.coords.longitude.toFixed(2)) };
        if (!inIndonesia(p)) { setBusy(false); setNote('Lokasi Anda di luar Indonesia. Pilih lokasi dari daftar.'); return; }
        const q = new URLSearchParams({ dekat: `${p.lat},${p.lng}` });
        if (radius) q.set('jarak', String(radius));
        router.push(`/ppiu?${q}`);
        setBusy(false);
      },
      (err) => {
        setBusy(false);
        setNote(err.code === err.PERMISSION_DENIED
          ? 'Izin lokasi ditolak. Izinkan akses lokasi di peramban, atau pilih lokasi dari daftar.'
          : 'Lokasi belum bisa diambil. Coba lagi, atau pilih lokasi dari daftar.');
      },
      { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 },
    );
  }

  return (
    <div>
      <button type="button" onClick={locate} disabled={busy} className={className}>
        <IconMapPin className="h-5 w-5" />{busy ? 'Mencari lokasi…' : 'Cari PPIU terdekat'}
      </button>
      {note && <p role="alert" className="mt-2 max-w-sm text-sm font-semibold text-danger">{note}</p>}
    </div>
  );
}
