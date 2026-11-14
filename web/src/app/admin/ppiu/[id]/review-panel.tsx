'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner, StatusPill } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { DOC_LABEL, type BankAccount, type TenantDoc } from '@/lib/tenant-types';

type DocDecision = { status: 'accepted' | 'rejected' | ''; note: string };

/** ADM-02 Tinjau PPIU: nilai tiap dokumen, verifikasi rekening, lalu putuskan (FR-ADM-02, FR-ADM-05). */
export function ReviewPanel({ tenantId, status, docs, banks, canVerify, canSuspend }: {
  tenantId: string; status: string; docs: TenantDoc[]; banks: BankAccount[]; canVerify: boolean; canSuspend: boolean;
}) {
  const router = useRouter();
  const current = new Map<string, TenantDoc>();
  for (const d of docs) if (!current.has(d.type)) current.set(d.type, d);
  const latest = [...current.values()];
  const [decisions, setDecisions] = useState<Record<string, DocDecision>>({});
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const reviewing = status === 'pending_review' && canVerify;

  async function call(key: string, fn: () => Promise<unknown>) {
    setBusy(key);
    setError(null);
    try { await fn(); router.refresh(); } catch (e) { setError(e instanceof ApiClientError ? e : errorMessage(e)); }
    setBusy(null);
  }

  function decide(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const decision = String(fd.get('decision'));
    const note = String(fd.get('note') ?? '').trim();
    const document_notes = Object.entries(decisions).filter(([, d]) => d.status)
      .map(([document_id, d]) => ({ document_id, status: d.status, ...(d.note ? { note: d.note } : {}) }));
    const label = decision === 'verified' ? 'memverifikasi PPIU ini (langsung tampil di marketplace)' : decision === 'needs_revision' ? 'meminta revisi' : 'menolak pengajuan ini';
    if (!confirm(`Yakin ${label}?`)) return;
    call('decide', () => api(`/platform/tenants/${tenantId}/verify`, { json: { decision, ...(note ? { note } : {}), document_notes } }));
  }

  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <div className="space-y-6">
      {(typeof error === 'string' || apiErr) && <Alert tone="danger">{typeof error === 'string' ? error : apiErr?.message}</Alert>}

      <section className="card p-5" aria-labelledby="dok-h">
        <h2 id="dok-h" className="text-xl font-extrabold">Dokumen legalitas</h2>
        {latest.length === 0 && <p className="mt-2 text-ink-600">Belum ada dokumen.</p>}
        <ul className="mt-2 divide-y divide-border-subtle">
          {latest.map((d) => (
            <li key={d.id} className="grid gap-3 py-4 md:grid-cols-[1fr_auto]">
              <div>
                <p className="font-bold">{DOC_LABEL[d.type] ?? d.type}</p>
                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                  <StatusPill status={d.status} />
                  {d.file?.url && <a href={d.file.url} target="_blank" rel="noopener" className="font-semibold text-primary-700 underline">Buka {d.file_name ?? 'file'}</a>}
                </p>
                {d.review_note && <p className="mt-1 text-sm text-ink-600">Catatan: {d.review_note}</p>}
              </div>
              {reviewing && (
                <div className="flex flex-wrap items-start gap-2">
                  <label className="sr-only" htmlFor={`d-${d.id}`}>Penilaian {DOC_LABEL[d.type]}</label>
                  <select id={`d-${d.id}`} className="input w-auto" value={decisions[d.id]?.status ?? ''}
                    onChange={(e) => setDecisions((x) => ({ ...x, [d.id]: { status: e.target.value as DocDecision['status'], note: x[d.id]?.note ?? '' } }))}>
                    <option value="">Belum dinilai</option>
                    <option value="accepted">Terima</option>
                    <option value="rejected">Tolak</option>
                  </select>
                  {decisions[d.id]?.status === 'rejected' && (
                    <input aria-label="Alasan penolakan dokumen" className="input w-56" placeholder="Alasan, mis. foto buram"
                      value={decisions[d.id]?.note ?? ''} onChange={(e) => setDecisions((x) => ({ ...x, [d.id]: { status: 'rejected', note: e.target.value } }))} />
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      </section>

      <section className="card p-5" aria-labelledby="rek-h">
        <h2 id="rek-h" className="text-xl font-extrabold">Rekening</h2>
        {banks.length === 0 && <p className="mt-2 text-ink-600">Belum ada rekening.</p>}
        <ul className="mt-2 divide-y divide-border-subtle">
          {banks.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 py-3">
              <div className="min-w-0 flex-1">
                <p className="font-bold">{a.bank_name} · <span className="num">{a.account_no}</span></p>
                <p className="text-sm text-ink-600">a.n. {a.account_holder}</p>
              </div>
              <StatusPill status={a.status} />
              {a.status === 'pending' && canVerify && (
                <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" disabled={!!busy}
                  onClick={() => { if (confirm(`Sudah dicek bahwa rekening ${a.bank_name} ${a.account_no} atas nama ${a.account_holder} sesuai badan usaha?`)) call(a.id, () => api(`/platform/bank-accounts/${a.id}/verify`, { method: 'POST' })); }}>
                  {busy === a.id && <Spinner />}Verifikasi rekening
                </button>
              )}
            </li>
          ))}
        </ul>
      </section>

      {reviewing && (
        <form onSubmit={decide} className="card space-y-4 border-gold-500 p-5" aria-labelledby="kep-h">
          <h2 id="kep-h" className="text-xl font-extrabold">Keputusan</h2>
          <fieldset className="grid gap-2 sm:grid-cols-3">
            <legend className="sr-only">Keputusan verifikasi</legend>
            {[['verified', 'Verifikasi', 'Tampil di marketplace'], ['needs_revision', 'Minta revisi', 'PPIU memperbaiki lalu mengajukan ulang'], ['rejected', 'Tolak', 'Pengajuan ditutup']].map(([v, l, d], i) => (
              <label key={v} className="flex cursor-pointer gap-3 rounded-[10px] border border-border-subtle p-3 has-[:checked]:border-primary-700 has-[:checked]:bg-primary-50">
                <input type="radio" name="decision" value={v} defaultChecked={i === 0} className="mt-1 h-5 w-5 accent-primary-700" />
                <span><span className="block font-bold">{l}</span><span className="text-sm text-ink-600">{d}</span></span>
              </label>
            ))}
          </fieldset>
          <Field id="note" label="Catatan untuk PPIU" hint="Wajib untuk revisi atau penolakan. Ditampilkan ke PPIU." error={apiErr?.field('note')}>
            <textarea id="note" name="note" rows={3} className="input" />
          </Field>
          <button className="btn btn-gold" disabled={busy === 'decide'}>{busy === 'decide' && <Spinner />}Simpan keputusan</button>
        </form>
      )}

      {canSuspend && (status === 'verified' || status === 'suspended') && (
        <form className="card space-y-3 p-5" onSubmit={(e) => {
          e.preventDefault();
          const reason = String(new FormData(e.currentTarget).get('reason') ?? '');
          const action = status === 'verified' ? 'suspend' : 'restore';
          if (!confirm(action === 'suspend' ? 'Tangguhkan PPIU ini? Semua paketnya langsung hilang dari marketplace.' : 'Pulihkan PPIU ini menjadi terverifikasi?')) return;
          call(action, () => api(`/platform/tenants/${tenantId}/${action}`, { json: { reason } }));
        }}>
          <h2 className="text-xl font-extrabold">{status === 'verified' ? 'Tangguhkan PPIU' : 'Pulihkan PPIU'}</h2>
          <Field id="reason" label="Alasan" hint="Tercatat di audit log dan ditampilkan ke PPIU." error={apiErr?.field('reason')}>
            <textarea id="reason" name="reason" rows={2} className="input" required minLength={3} />
          </Field>
          <button className={`btn ${status === 'verified' ? 'border border-danger text-danger hover:bg-danger-bg' : 'btn-primary'}`} disabled={!!busy}>
            {(busy === 'suspend' || busy === 'restore') && <Spinner />}{status === 'verified' ? 'Tangguhkan' : 'Pulihkan'}
          </button>
        </form>
      )}
    </div>
  );
}
