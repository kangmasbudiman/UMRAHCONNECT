/** Pedoman microcopy (UI/UX Design §8): Rp32.500.000, "10 Feb 2027", zona waktu WIB. */
export function rupiah(amount: number): string {
  return 'Rp' + Math.round(amount).toLocaleString('id-ID');
}

/** Juta singkat untuk kartu: Rp32,5 jt */
export function rupiahShort(amount: number): string {
  const jt = amount / 1_000_000;
  return 'Rp' + jt.toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
}

const tz = 'Asia/Jakarta';

function asDate(isoDate: string): Date {
  return new Date(isoDate.length === 10 ? `${isoDate}T00:00:00+07:00` : isoDate);
}

export function tanggal(isoDate: string): string {
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: tz }).format(asDate(isoDate));
}

export function tanggalPanjang(isoDate: string): string {
  return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: tz }).format(asDate(isoDate));
}

export function bulanTahun(yyyyMm: string): string {
  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: tz }).format(asDate(`${yyyyMm}-01`));
}

export const ROOM_LABEL: Record<string, string> = {
  quad: 'Quad (4 orang)',
  triple: 'Triple (3 orang)',
  double: 'Double (2 orang)',
};

/** Link WhatsApp dengan pesan terisi (FR-MKT-10). */
export function waLink(phone: string | null | undefined, text: string): string | null {
  if (!phone) return null;
  const digits = phone.replace(/\D/g, '').replace(/^0/, '62');
  return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
}

/** Pilihan "budget per orang" dari batas harga termurah (facets). */
export function budgetSteps(priceMin: number): { value: string; label: string }[] {
  return [30_000_000, 35_000_000, 40_000_000, 50_000_000]
    .filter((v) => v >= priceMin)
    .map((v) => ({ value: String(v), label: `≤ ${rupiah(v)}` }));
}
