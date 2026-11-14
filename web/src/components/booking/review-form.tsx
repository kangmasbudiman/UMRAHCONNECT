'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Spinner } from '@/components/form';
import { IconStar } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

const LABEL = ['', 'Sangat kecewa', 'Kurang', 'Cukup', 'Baik', 'Sangat baik'];

/** Ulasan terverifikasi: hanya muncul setelah booking berangkat. */
export function ReviewForm({ bookingId, tenantName }: { bookingId: string; tenantName: string }) {
  const router = useRouter();
  const [rating, setRating] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <form className="card space-y-3 border-gold-500 p-5" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const body = String(new FormData(e.currentTarget).get('body') ?? '').trim();
      if (!rating) { setError('Pilih jumlah bintang.'); return; }
      setBusy(true);
      setError(null);
      try { await api(`/me/bookings/${bookingId}/review`, { json: { rating, body } }); router.refresh(); }
      catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
      setBusy(false);
    }}>
      <h2 className="text-lg font-extrabold">Bagaimana perjalanan umrah Anda bersama {tenantName}?</h2>
      <p className="text-sm text-ink-600">Ulasan Anda tampil dengan tanda &quot;Berangkat lewat UMRAH CONNECT&quot; dan membantu calon jamaah lain memilih.</p>
      <fieldset>
        <legend className="mb-1 font-semibold">Penilaian</legend>
        <div className="flex items-center gap-1">
          {[1, 2, 3, 4, 5].map((n) => (
            <button key={n} type="button" aria-pressed={rating === n} aria-label={`${n} bintang, ${LABEL[n]}`} onClick={() => setRating(n)}
              className="rounded-lg p-1.5 text-gold-500 hover:bg-gold-50">
              <IconStar className={`h-8 w-8 ${n <= rating ? 'fill-current' : ''}`} />
            </button>
          ))}
          {rating > 0 && <span className="ml-2 font-semibold">{LABEL[rating]}</span>}
        </div>
      </fieldset>
      <div>
        <label htmlFor="rv-body" className="mb-1.5 block font-semibold">Cerita Anda</label>
        <textarea id="rv-body" name="body" rows={4} className="input" placeholder="Hotel, muthawif, makanan, ketepatan jadwal, pelayanan selama di tanah suci…" />
        {apiErr?.field('body') && <p role="alert" className="mt-1 text-sm font-semibold text-danger">{apiErr.field('body')}</p>}
      </div>
      {(typeof error === 'string' || (apiErr && !apiErr.errors)) && <Alert tone="danger">{typeof error === 'string' ? error : apiErr!.message}</Alert>}
      <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Kirim ulasan</button>
    </form>
  );
}
