import type { Rating } from '@/lib/api';
import { IconSparkles, IconStar, IconUsers, IconVerified } from './icons';

/** Bintang rating 1–5 (dekoratif; nilai dibacakan lewat teks). */
export function RatingStars({ value, className = 'h-4 w-4' }: { value: number; className?: string }) {
  return (
    <span aria-hidden="true" className="inline-flex text-gold-500">
      {[1, 2, 3, 4, 5].map((n) => (
        <span key={n} className={n <= Math.round(value) ? '' : 'opacity-30'}><IconStar className={`${className} fill-current`} /></span>
      ))}
    </span>
  );
}

/** Ringkas: ★ 4,8 (12 ulasan terverifikasi). */
export function RatingBadge({ rating }: { rating: Rating | null | undefined }) {
  if (!rating || !rating.count || rating.avg === null) return null;
  return (
    <span className="inline-flex items-center gap-1 text-sm">
      <IconStar className="h-4 w-4 fill-current text-gold-500" />
      <span className="num font-bold">{rating.avg.toLocaleString('id-ID', { minimumFractionDigits: 1 })}</span>
      <span className="text-ink-600">({rating.count} ulasan)</span>
    </span>
  );
}

/** Skor transparansi paket 0–100. */
export function TransparencyBadge({ score }: { score: number | undefined }) {
  if (score === undefined) return null;
  const tone = score >= 90 ? 'bg-success-bg text-success border-success' : score >= 70 ? 'bg-info-bg text-info border-info' : 'bg-warning-bg text-warning border-warning';
  return (
    <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-bold ${tone}`} title="Skor kelengkapan informasi paket">
      <IconVerified className="h-3.5 w-3.5" />Transparansi <span className="num">{score}</span>
    </span>
  );
}

export function ElderlyBadge({ show }: { show: boolean | undefined }) {
  if (!show) return null;
  return (
    <span className="inline-flex items-center gap-1 rounded-full border border-primary-600 bg-primary-50 px-2 py-0.5 text-xs font-bold text-primary-800" title="Kedua hotel paling jauh 300 m dari masjid">
      <IconUsers className="h-3.5 w-3.5" />Ramah lansia
    </span>
  );
}

/** Penanda paket berbayar (Paket pilihan) agar promosi tidak menyamar sebagai rekomendasi. */
export function PromoBadge() {
  return (
    <span className="inline-flex items-center gap-1 rounded-full border border-gold-600 bg-gold-50 px-2 py-0.5 text-xs font-bold text-gold-700" title="Paket ini dipromosikan PPIU (berbayar)">
      <IconSparkles className="h-3.5 w-3.5" />Promosi
    </span>
  );
}
