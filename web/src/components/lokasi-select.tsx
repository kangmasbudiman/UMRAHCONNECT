import type { Facets } from '@/lib/api';

/**
 * Filter "Lokasi PPIU" tanpa JavaScript: satu dropdown, dikelompokkan per provinsi.
 * Nilai = kode provinsi (semua kab/kota) atau kode kab/kota. Hanya daerah yang punya PPIU terverifikasi.
 */
export function LokasiSelect({ id, value, regions, name = 'lokasi', className = 'input' }: {
  id: string; value?: string; regions: Facets['ppiu_regions']; name?: string; className?: string;
}) {
  return (
    <select id={id} name={name} defaultValue={value ?? ''} className={className}>
      <option value="">Seluruh Indonesia</option>
      {regions.map((p) => (
        <optgroup key={p.code} label={p.name}>
          <option value={p.code}>Semua di {p.name} ({p.count})</option>
          {p.regencies.map((r) => <option key={r.code} value={r.code}>{r.name} ({r.count})</option>)}
        </optgroup>
      ))}
    </select>
  );
}
