'use client';

import { useEffect, useState, type ReactNode } from 'react';

export type RegionRef = { code: string; name: string | null } | null;
export type Region = { province: RegionRef; regency: RegionRef; district: RegionRef; village: RegionRef } | null;
type Option = { code: string; name: string };

const LEVELS = [
  { key: 'province', label: 'Provinsi', placeholder: 'Pilih provinsi', auto: 'address-level1' },
  { key: 'regency', label: 'Kabupaten/Kota', placeholder: 'Pilih kabupaten/kota', auto: 'address-level2' },
  { key: 'district', label: 'Kecamatan', placeholder: 'Pilih kecamatan', auto: 'address-level3' },
  { key: 'village', label: 'Kelurahan/Desa', placeholder: 'Pilih kelurahan/desa', auto: 'address-level4' },
] as const;

/** Data wilayah statis: cukup diambil sekali per induk selama halaman terbuka. */
const cache = new Map<string, Promise<Option[]>>();
function loadChildren(parent: string | null): Promise<Option[]> {
  const key = parent ?? '';
  if (!cache.has(key)) {
    const p = fetch(`/api/v1/public/regions${parent ? `?parent=${encodeURIComponent(parent)}` : ''}`, { headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('Gagal memuat wilayah'))))
      .then((j: { data: Option[] }) => j.data);
    p.catch(() => cache.delete(key));
    cache.set(key, p);
  }
  return cache.get(key)!;
}

/**
 * Alamat bertingkat sesuai Kepmendagri: Provinsi → Kab/Kota → Kecamatan → Kelurahan/Desa.
 * Mengirim satu field tersembunyi `name` (kode kelurahan/desa); server menurunkan tingkat di atasnya.
 */
export function RegionSelect({
  name = 'region_code', initial = null, required = true, error, idPrefix = 'reg', disabled = false, onChange, after,
}: {
  name?: string; initial?: Region; required?: boolean; error?: string; idPrefix?: string; disabled?: boolean;
  onChange?: (villageCode: string, names: string[]) => void;
  /** Isian lanjutan di bawah kelurahan (nama jalan, kode pos), agar urutan alamat sama di semua formulir. */
  after?: ReactNode;
}) {
  const [codes, setCodes] = useState<string[]>([
    initial?.province?.code ?? '', initial?.regency?.code ?? '', initial?.district?.code ?? '', initial?.village?.code ?? '',
  ]);
  const [lists, setLists] = useState<(Option[] | null)[]>([null, null, null, null]);
  const [loadError, setLoadError] = useState(false);

  // Muat daftar untuk setiap tingkat yang induknya sudah dipilih (termasuk nilai awal saat mengedit).
  const parents = [null, codes[0] || null, codes[1] || null, codes[2] || null];
  const parentKey = parents.join('|');
  useEffect(() => {
    let alive = true;
    parents.forEach((parent, i) => {
      if (i > 0 && !parent) return;
      loadChildren(parent).then(
        (opts) => alive && setLists((prev) => { const n = [...prev]; n[i] = opts; return n; }),
        () => alive && setLoadError(true),
      );
    });
    return () => { alive = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- parentKey merangkum isi `parents`
  }, [parentKey]);

  function pick(level: number, code: string) {
    // Mengganti satu tingkat mengosongkan semua tingkat di bawahnya.
    const next = codes.map((c, i) => (i < level ? c : i === level ? code : ''));
    setCodes(next);
    setLists((prev) => prev.map((l, i) => (i <= level ? l : null)));
    // Nama wilayah terpilih, dari yang paling rinci (kelurahan → provinsi), mis. untuk mengarahkan peta.
    const names = next.map((c, i) => lists[i]?.find((o) => o.code === c)?.name ?? '').filter(Boolean).reverse();
    onChange?.(next[3], names);
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <input type="hidden" name={name} value={codes[3]} />
      {LEVELS.map((lv, i) => {
        const id = `${idPrefix}-${lv.key}`;
        const list = lists[i];
        const waitingParent = i > 0 && !codes[i - 1];
        const loading = !waitingParent && list === null;
        return (
          <div key={lv.key}>
            <label htmlFor={id} className="mb-1.5 block font-semibold">
              {lv.label}{!required && <span className="font-normal text-ink-600"> (opsional)</span>}
            </label>
            <select
              id={id}
              className="input"
              value={codes[i]}
              onChange={(e) => pick(i, e.target.value)}
              disabled={disabled || waitingParent || loading}
              required={required}
              autoComplete={lv.auto}
              aria-invalid={i === 3 && error ? true : undefined}
              aria-describedby={i === 3 && error ? `${idPrefix}-error` : undefined}
            >
              <option value="">{waitingParent ? `Pilih ${LEVELS[i - 1].label.toLowerCase()} dulu` : loading ? 'Memuat…' : lv.placeholder}</option>
              {(list ?? []).map((o) => <option key={o.code} value={o.code}>{o.name}</option>)}
            </select>
          </div>
        );
      })}
      {(error || loadError) && (
        <p className="text-sm font-semibold text-danger sm:col-span-2" id={`${idPrefix}-error`} role="alert">
          {error ?? 'Daftar wilayah gagal dimuat. Muat ulang halaman lalu coba lagi.'}
        </p>
      )}
      {after}
    </div>
  );
}
