'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useSyncExternalStore } from 'react';
import { IconTransfer, IconX } from './icons';

/** Baki "Bandingkan paket": maksimal 3, disimpan di localStorage perangkat (tanpa akun). */
type Item = { key: string; name: string };
const KEY = 'uc_compare';
export const COMPARE_MAX = 3;
const EMPTY: Item[] = [];
let cache: { raw: string | null; items: Item[] } = { raw: null, items: EMPTY };

function read(): Item[] {
  const raw = typeof window === 'undefined' ? null : window.localStorage.getItem(KEY);
  if (raw === cache.raw) return cache.items;
  let items: Item[] = EMPTY;
  try { items = raw ? (JSON.parse(raw) as Item[]).slice(0, COMPARE_MAX) : EMPTY; } catch { items = EMPTY; }
  cache = { raw, items };
  return items;
}
function write(items: Item[]) {
  window.localStorage.setItem(KEY, JSON.stringify(items));
  window.dispatchEvent(new Event('uc-compare'));
}
function subscribe(cb: () => void) {
  window.addEventListener('storage', cb);
  window.addEventListener('uc-compare', cb);
  return () => { window.removeEventListener('storage', cb); window.removeEventListener('uc-compare', cb); };
}
export function useCompare() {
  return useSyncExternalStore(subscribe, read, () => EMPTY);
}

export function CompareToggle({ itemKey, name, className = '' }: { itemKey: string; name: string; className?: string }) {
  const items = useCompare();
  const on = items.some((i) => i.key === itemKey);
  const full = !on && items.length >= COMPARE_MAX;
  return (
    <button type="button" aria-pressed={on} disabled={full} title={full ? `Maksimal ${COMPARE_MAX} paket` : undefined}
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        const cur = read(); // baca terbaru, bukan dari render sebelumnya
        const has = cur.some((i) => i.key === itemKey);
        if (!has && cur.length >= COMPARE_MAX) return;
        write(has ? cur.filter((i) => i.key !== itemKey) : [...cur, { key: itemKey, name }]);
      }}
      className={`relative z-10 inline-flex min-h-10 items-center gap-1.5 rounded-[10px] border px-3 text-sm font-semibold disabled:opacity-50 ${on ? 'border-primary-700 bg-primary-700 text-white' : 'border-border-input bg-white text-primary-700 hover:border-primary-600'} ${className}`}>
      <IconTransfer className="h-4 w-4" />{on ? 'Dibandingkan' : 'Bandingkan'}
    </button>
  );
}

export function CompareTray() {
  const items = useCompare();
  const path = usePathname();
  if (items.length === 0 || path === '/bandingkan') return null;
  return (
    <div role="region" aria-label="Paket yang dibandingkan" className="fixed inset-x-0 bottom-20 z-40 px-4 lg:bottom-4">
      <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-2 rounded-2xl border border-primary-600 bg-white p-3 shadow-raised">
        <span className="flex items-center gap-2 font-bold text-primary-800"><IconTransfer className="h-5 w-5" />Bandingkan ({items.length}/{COMPARE_MAX})</span>
        <ul className="flex min-w-0 flex-1 flex-wrap gap-1.5">
          {items.map((i) => (
            <li key={i.key} className="inline-flex max-w-52 items-center gap-1 rounded-full bg-surface py-1 pl-3 pr-1 text-sm">
              <span className="truncate">{i.name}</span>
              <button type="button" aria-label={`Hapus ${i.name} dari perbandingan`} className="rounded-full p-1 hover:bg-white" onClick={() => write(read().filter((x) => x.key !== i.key))}><IconX className="h-4 w-4" /></button>
            </li>
          ))}
        </ul>
        <button type="button" className="text-sm font-semibold text-ink-600 underline" onClick={() => write([])}>Kosongkan</button>
        <Link href={`/bandingkan?p=${items.map((i) => i.key).join(',')}`} aria-disabled={items.length < 2}
          className={`btn btn-primary min-h-10 px-4 ${items.length < 2 ? 'pointer-events-none opacity-50' : ''}`}>
          {items.length < 2 ? 'Pilih minimal 2' : 'Bandingkan sekarang'}
        </Link>
      </div>
    </div>
  );
}
