'use client';

import { useEffect, useState } from 'react';

/** Sisa waktu bayar DP; diperbarui tiap 30 detik. */
export function Countdown({ until }: { until: string }) {
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    // Waktu hanya dibaca di klien (hindari beda render server/klien).
    const first = setTimeout(() => setNow(Date.now()), 0);
    const t = setInterval(() => setNow(Date.now()), 30_000);
    return () => { clearTimeout(first); clearInterval(t); };
  }, []);
  if (now === null) return null;
  const ms = new Date(until).getTime() - now;
  if (ms <= 0) return <span className="font-bold text-danger">Tenggat terlewati</span>;
  const h = Math.floor(ms / 3_600_000);
  const m = Math.floor((ms % 3_600_000) / 60_000);
  return <span className="num font-bold">{h > 0 ? `${h} jam ` : ''}{m} menit lagi</span>;
}

export function CopyButton({ text, label = 'Salin' }: { text: string; label?: string }) {
  const [done, setDone] = useState(false);
  return (
    <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" onClick={async () => {
      await navigator.clipboard.writeText(text);
      setDone(true);
      setTimeout(() => setDone(false), 2000);
    }}>
      {done ? 'Tersalin ✓' : label}<span role="status" className="sr-only">{done ? 'Tersalin' : ''}</span>
    </button>
  );
}
