'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, errorMessage } from '@/lib/client-api';

export type SupportSession = { id: string; tenant_id: string; reason: string; ticket_no: string; started_at: string; expires_at: string; ended_at: string | null; active: boolean };
const hm = (iso: string) => new Intl.DateTimeFormat('id-ID', { timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date(iso));

/** Akses dukungan baca-saja 60 menit; Owner PPIU diberi tahu, setiap akses tercatat di audit log. */
export function SupportPanel({ tenantId, session }: { tenantId: string; session: SupportSession | null }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (session?.active) {
    return (
      <div className="space-y-3">
        <Alert tone="info" title={`Sesi aktif sampai ${hm(session.expires_at)} WIB`}>Tiket {session.ticket_no}: {session.reason}</Alert>
        <div className="flex flex-wrap gap-2">
          <Link href={`/admin/ppiu/${tenantId}/dukungan?sesi=${session.id}`} className="btn btn-primary">Lihat data PPIU (baca-saja)</Link>
          <button type="button" className="btn btn-outline" disabled={busy} onClick={async () => {
            setBusy(true);
            try { await api(`/platform/support-sessions/${session.id}`, { method: 'DELETE' }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
            setBusy(false);
          }}>{busy && <Spinner />}Akhiri sesi</button>
        </div>
        {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
      </div>
    );
  }
  return (
    <form className="space-y-3" onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      setBusy(true);
      setError(null);
      try { await api('/platform/support-sessions', { json: { tenant_id: tenantId, reason: fd.get('reason'), ticket_no: fd.get('ticket') } }); router.refresh(); }
      catch (err) { setError(errorMessage(err)); }
      setBusy(false);
    }}>
      <p className="text-sm text-ink-600">Buka hanya untuk menangani laporan. Owner PPIU diberi tahu dan setiap halaman yang Anda buka tercatat.</p>
      <Field id="sp-ticket" label="Nomor tiket"><input id="sp-ticket" name="ticket" className="input" required maxLength={50} placeholder="CS-0012" /></Field>
      <Field id="sp-reason" label="Alasan"><textarea id="sp-reason" name="reason" rows={2} className="input" required minLength={5} /></Field>
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
      <button className="btn btn-outline" disabled={busy}>{busy && <Spinner />}Buka akses 60 menit</button>
    </form>
  );
}
