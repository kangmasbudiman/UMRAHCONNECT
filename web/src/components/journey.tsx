import type { Journey } from '@/lib/api';
import { tanggal } from '@/lib/format';
import { IconCheck } from './icons';

/** "Lacak Umrah": linimasa booking dari dibuat sampai pulang (tanpa data pribadi). */
export function JourneyTimeline({ journey, title = 'Lacak Umrah' }: { journey: Journey; title?: string }) {
  const ended = journey.state === 'cancelled' || journey.state === 'expired';
  return (
    <section className="card p-5" aria-labelledby="journey-h">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <h2 id="journey-h" className="text-lg font-extrabold">{title}</h2>
        <p className="text-sm font-semibold text-primary-700">{ended ? (journey.state === 'cancelled' ? 'Booking dibatalkan' : 'Booking kedaluwarsa') : `${journey.progress}% selesai`}</p>
      </div>
      {!ended && (
        <div className="mt-2 h-2 overflow-hidden rounded-full bg-surface" role="progressbar" aria-valuenow={journey.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Kemajuan persiapan">
          <div className="h-full rounded-full bg-gradient-to-r from-primary-700 to-gold-500" style={{ width: `${journey.progress}%` }} />
        </div>
      )}
      <ol className="mt-4">
        {journey.steps.map((s, i) => {
          const last = i === journey.steps.length - 1;
          const dot = s.status === 'done' ? 'bg-primary-700 text-white' : s.status === 'current' ? 'border-2 border-gold-500 bg-gold-50 text-gold-700' : 'border-2 border-border-input bg-white text-ink-600';
          return (
            <li key={s.key} className="relative flex gap-3 pb-4 last:pb-0">
              {!last && <span aria-hidden="true" className={`absolute left-[15px] top-8 h-[calc(100%-1.5rem)] w-0.5 ${s.status === 'done' ? 'bg-primary-700' : 'bg-border-subtle'}`} />}
              <span className={`relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold ${dot}`}>
                {s.status === 'done' ? <IconCheck className="h-4 w-4" /> : i + 1}
              </span>
              <div className={`min-w-0 pt-1 ${s.status === 'skipped' ? 'text-ink-600 line-through' : ''}`}>
                <p className={`font-semibold ${s.status === 'current' ? 'text-primary-800' : ''}`}>
                  {s.label}
                  {s.status === 'current' && <span className="ml-2 rounded-full bg-gold-100 px-2 py-0.5 text-xs font-bold text-gold-700">Sedang ditunggu</span>}
                </p>
                {(s.date || s.note) && (
                  <p className="text-sm text-ink-600">{s.date && (s.status === 'done' || ['manasik', 'departed', 'returned'].includes(s.key)) ? tanggal(s.date) : ''}{s.date && s.note ? ' · ' : ''}{s.note}</p>
                )}
                <span className="sr-only">{s.status === 'done' ? '(selesai)' : s.status === 'current' ? '(sedang ditunggu)' : s.status === 'skipped' ? '(tidak berlanjut)' : '(belum)'}</span>
              </div>
            </li>
          );
        })}
      </ol>
    </section>
  );
}
