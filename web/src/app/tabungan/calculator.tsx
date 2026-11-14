'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';
import { Alert, Spinner } from '@/components/form';
import { IconBell, IconPiggy } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { rupiah } from '@/lib/format';

type Pkg = { id: string; name: string; key: string; priceFrom: number } | null;
const digits = (v: string) => Number(v.replace(/\D/g, '')) || 0;
const fmt = (n: number) => (n ? n.toLocaleString('id-ID') : '');

function monthsBetween(target: string): number {
  if (!target) return 0;
  const now = new Date();
  const [y, m] = target.split('-').map(Number);
  return (y - now.getFullYear()) * 12 + (m - 1 - now.getMonth());
}

/** Kalkulator tabungan umrah untuk jamaah: berapa yang perlu disisihkan per bulan. */
export function SavingsCalculator({ pkg, signedIn }: { pkg: Pkg; signedIn: boolean }) {
  const router = useRouter();
  const defMonth = (() => { const d = new Date(); d.setMonth(d.getMonth() + 12); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`; })();
  const [price, setPrice] = useState(pkg?.priceFrom ?? 32_000_000);
  const [persons, setPersons] = useState(1);
  const [month, setMonth] = useState(defMonth);
  const [saved, setSaved] = useState(0);
  const [reminder, setReminder] = useState(25);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const target = price * persons;
  const months = Math.max(0, monthsBetween(month));
  const remaining = Math.max(0, target - saved);
  const monthly = months > 0 ? Math.ceil(remaining / months / 1000) * 1000 : remaining;
  const daily = Math.ceil(monthly / 30 / 1000) * 1000;
  const progress = target ? Math.min(100, Math.floor((saved / target) * 100)) : 0;
  const nextHref = useMemo(() => `/masuk?next=${encodeURIComponent(`/tabungan${pkg ? `?paket=${pkg.key}` : ''}`)}`, [pkg]);

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
      <form className="card grid gap-4 p-5 sm:grid-cols-2" onSubmit={(e) => e.preventDefault()}>
        {pkg && <p className="sm:col-span-2 rounded-[10px] bg-primary-50 p-3 text-sm">Target dari paket <strong>{pkg.name}</strong> (harga mulai {rupiah(pkg.priceFrom)} per orang). Anda bisa mengubahnya.</p>}
        <div>
          <label htmlFor="tb-price" className="mb-1.5 block font-semibold">Biaya per orang</label>
          <input id="tb-price" inputMode="numeric" className="input num" value={fmt(price)} onChange={(e) => setPrice(digits(e.target.value))} />
        </div>
        <div>
          <label htmlFor="tb-persons" className="mb-1.5 block font-semibold">Jumlah orang</label>
          <input id="tb-persons" type="number" min={1} max={20} className="input num" value={persons} onChange={(e) => setPersons(Math.min(20, Math.max(1, Number(e.target.value) || 1)))} />
        </div>
        <div>
          <label htmlFor="tb-month" className="mb-1.5 block font-semibold">Target berangkat</label>
          <input id="tb-month" type="month" className="input" value={month} onChange={(e) => setMonth(e.target.value)} />
        </div>
        <div>
          <label htmlFor="tb-saved" className="mb-1.5 block font-semibold">Sudah terkumpul</label>
          <input id="tb-saved" inputMode="numeric" className="input num" value={fmt(saved)} placeholder="0" onChange={(e) => setSaved(digits(e.target.value))} />
        </div>
        <div className="sm:col-span-2">
          <label htmlFor="tb-rem" className="mb-1.5 block font-semibold">Ingatkan saya setiap tanggal</label>
          <select id="tb-rem" className="input w-auto" value={reminder} onChange={(e) => setReminder(Number(e.target.value))}>
            {Array.from({ length: 28 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
          <span className="ml-2 text-sm text-ink-600">misalnya tanggal gajian</span>
        </div>
      </form>

      <aside className="card overflow-hidden lg:sticky lg:top-24 lg:self-start">
        <div className="bg-hero p-5 text-white">
          <p className="flex items-center gap-2 text-sm text-white/85"><IconPiggy className="h-5 w-5 text-gold-500" />Sisihkan per bulan</p>
          <p className="num text-3xl font-extrabold">{rupiah(monthly)}</p>
          <p className="text-sm text-white/85">≈ {rupiah(daily)} per hari · selama {months} bulan</p>
        </div>
        <div className="space-y-3 p-5 text-sm">
          <div className="flex justify-between"><span className="text-ink-600">Target total</span><span className="num font-bold">{rupiah(target)}</span></div>
          <div className="flex justify-between"><span className="text-ink-600">Kekurangan</span><span className="num font-bold">{rupiah(remaining)}</span></div>
          <div className="h-2 overflow-hidden rounded-full bg-surface" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100} aria-label="Sudah terkumpul">
            <div className="h-full rounded-full bg-gold-500" style={{ width: `${progress}%` }} />
          </div>
          {months <= 0 && <Alert tone="warning">Pilih bulan target setelah bulan ini.</Alert>}
          {error && <Alert tone="danger">{error}</Alert>}
          {signedIn ? (
            <button type="button" className="btn btn-gold w-full" disabled={busy || months <= 0 || target < 1_000_000} onClick={async () => {
              setBusy(true);
              setError(null);
              try {
                await api('/me/savings-plans', { json: {
                  package_id: pkg?.id ?? null, label: pkg ? pkg.name : null, persons, target_amount: target,
                  target_date: `${month}-01`, initial_amount: saved, reminder_day: reminder,
                } });
                router.push('/akun/tabungan');
              } catch (e) { setError(e instanceof ApiClientError ? (Object.values(e.errors ?? {})[0]?.[0] ?? e.message) : errorMessage(e)); setBusy(false); }
            }}>{busy ? <Spinner /> : <IconBell className="h-5 w-5" />}Simpan rencana & pengingat</button>
          ) : (
            <Link href={nextHref} className="btn btn-gold w-full"><IconBell className="h-5 w-5" />Masuk untuk simpan & dapat pengingat</Link>
          )}
          <p className="text-xs text-ink-600">UMRAH CONNECT hanya mencatat rencana dan setoran Anda. Uang tetap Anda simpan sendiri, misalnya di tabungan bank atau tabungan haji/umrah syariah.</p>
        </div>
      </aside>
    </div>
  );
}
