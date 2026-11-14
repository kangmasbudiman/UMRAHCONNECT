'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { IconCheck, IconUndo } from '@/components/icons';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

export type Milestone = { type: string; label: string; status: 'pending' | 'done'; happened_on: string | null; note: string | null; updated_at: string | null };
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());

/** Satu tahap Lacak Umrah: tandai selesai (dengan tanggal & catatan) atau batalkan tanda. Pemesan diberi tahu saat selesai. */
export function MilestoneForm({ tenantId, departureId, m, canEdit, activeBookings }: { tenantId: string; departureId: string; m: Milestone; canEdit: boolean; activeBookings: number }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const done = m.status === 'done';
  const save = async (status: 'done' | 'pending', fd?: FormData) => {
    setBusy(true);
    setError(null);
    try {
      await api(`/tenants/${tenantId}/departures/${departureId}/milestones/${m.type}`, { method: 'PUT', json: {
        status, happened_on: fd ? String(fd.get('happened_on') || '') || null : null, note: fd ? String(fd.get('note') || '').trim() || null : null,
      } });
      router.refresh();
    } catch (e) { setError(e instanceof ApiClientError ? (e.field('happened_on') ?? e.message) : errorMessage(e)); }
    setBusy(false);
  };
  return (
    <form className={`card p-4 ${done ? 'border-success' : ''}`} noValidate onSubmit={(e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      if (!done && activeBookings > 0 && !confirm(`Tandai "${m.label}" selesai? ${activeBookings} pemesan akan diberi tahu.`)) return;
      save('done', fd);
    }}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-2 font-bold">
          <span className={`flex h-7 w-7 items-center justify-center rounded-full ${done ? 'bg-success text-white' : 'border-2 border-border-input text-ink-600'}`}>{done ? <IconCheck className="h-4 w-4" /> : null}</span>
          {m.label}
        </p>
        {done && canEdit && <button type="button" className="inline-flex min-h-10 items-center gap-1 px-2 text-sm font-semibold text-ink-600 underline" disabled={busy} onClick={() => save('pending')}><IconUndo className="h-4 w-4" />Batalkan tanda</button>}
      </div>
      {canEdit ? (
        <div className="mt-3 grid gap-3 sm:grid-cols-[170px_1fr_auto] sm:items-end">
          <div>
            <label htmlFor={`d-${m.type}`} className="mb-1 block text-sm font-semibold">{m.type === 'manasik' ? 'Tanggal manasik' : 'Tanggal'}</label>
            <input id={`d-${m.type}`} name="happened_on" type="date" className="input" defaultValue={m.happened_on ?? (m.type === 'manasik' ? '' : today())} />
          </div>
          <div>
            <label htmlFor={`n-${m.type}`} className="mb-1 block text-sm font-semibold">Catatan untuk jamaah <span className="font-normal text-ink-600">(opsional)</span></label>
            <input id={`n-${m.type}`} name="note" className="input" defaultValue={m.note ?? ''} maxLength={500} placeholder={m.type === 'manasik' ? 'Tempat dan jam, mis. Masjid Agung Jambi, 08.00 WIB' : 'Mis. nomor penerbangan, nama hotel'} />
          </div>
          <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}{done ? 'Perbarui' : 'Tandai selesai'}</button>
        </div>
      ) : (
        (m.happened_on || m.note) && <p className="mt-2 text-sm text-ink-600">{m.happened_on}{m.note && ` · ${m.note}`}</p>
      )}
      {error && <p role="alert" className="mt-2 text-sm font-semibold text-danger">{error}</p>}
    </form>
  );
}
