'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { IconChat } from '@/components/icons';
import { api, errorMessage } from '@/lib/client-api';

export function ReplyForm({ tenantId, reviewId, current }: { tenantId: string; reviewId: string; current: string | null }) {
  const router = useRouter();
  const [open, setOpen] = useState(!current);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  if (!open) return <button type="button" className="mt-2 inline-flex min-h-10 items-center gap-1 text-sm font-semibold text-primary-700 underline" onClick={() => setOpen(true)}><IconChat className="h-4 w-4" />Ubah balasan</button>;
  return (
    <form className="mt-3 space-y-2" onSubmit={async (e) => {
      e.preventDefault();
      const reply = String(new FormData(e.currentTarget).get('reply') ?? '').trim();
      if (reply.length < 5) { setError('Balasan minimal 5 karakter.'); return; }
      setBusy(true);
      setError(null);
      try { await api(`/tenants/${tenantId}/reviews/${reviewId}/reply`, { json: { reply } }); setOpen(false); router.refresh(); }
      catch (err) { setError(errorMessage(err)); }
      setBusy(false);
    }}>
      <label htmlFor={`rp-${reviewId}`} className="sr-only">Balasan</label>
      <textarea id={`rp-${reviewId}`} name="reply" rows={2} className="input" defaultValue={current ?? ''} placeholder="Balas dengan sopan; balasan tampil di halaman paket." />
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
      <button className="btn btn-outline min-h-10 px-4" disabled={busy}>{busy ? <Spinner /> : <IconChat className="h-5 w-5" />}Kirim balasan</button>
    </form>
  );
}
