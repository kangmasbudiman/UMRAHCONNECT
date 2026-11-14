'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { FOLLOW_UP, type FollowUpStatus, type Prospect } from '@/lib/agent-types';

type Opt = { id: string; name: string };
type PkgOpt = Opt & { tenant_id: string };
const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());

/** Tambah / ubah prospek. PPIU hanya mitra aktif; paket mengikuti PPIU yang dipilih. */
export function ProspectForm({ tenants, packages, prospect, onDone }: { tenants: Opt[]; packages: PkgOpt[]; prospect?: Prospect; onDone?: () => void }) {
  const router = useRouter();
  const [tenantId, setTenantId] = useState(prospect?.tenant?.id ?? '');
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;
  const p = prospect?.id ?? 'baru';
  return (
    <form className="grid gap-3 sm:grid-cols-2" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const form = e.currentTarget;
      const fd = new FormData(form);
      const body = {
        name: String(fd.get('name') ?? '').trim(), phone: String(fd.get('phone') ?? '').trim(), notes: String(fd.get('notes') ?? '').trim() || null,
        tenant_id: tenantId || null, package_id: String(fd.get('package_id') ?? '') || null,
        next_follow_up_on: String(fd.get('next_follow_up_on') ?? '') || null,
        ...(prospect ? { follow_up_status: fd.get('follow_up_status') } : {}),
      };
      setBusy(true);
      setError(null);
      try {
        await api(prospect ? `/agent/prospects/${prospect.id}` : '/agent/prospects', { method: prospect ? 'PATCH' : 'POST', json: body });
        if (!prospect) { form.reset(); setTenantId(''); }
        onDone?.();
        router.refresh();
      } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
      setBusy(false);
    }}>
      <Field id={`pr-name-${p}`} label="Nama calon jamaah" error={apiErr?.field('name')}><input id={`pr-name-${p}`} name="name" className="input" defaultValue={prospect?.name} autoComplete="off" /></Field>
      <Field id={`pr-phone-${p}`} label="Nomor HP/WhatsApp" error={apiErr?.field('phone')}><input id={`pr-phone-${p}`} name="phone" type="tel" inputMode="tel" className="input" defaultValue={prospect?.phone} placeholder="0812…" /></Field>
      <Field id={`pr-tenant-${p}`} label="PPIU" optional error={apiErr?.field('tenant_id')}>
        <select id={`pr-tenant-${p}`} className="input" value={tenantId} onChange={(e) => setTenantId(e.target.value)}>
          <option value="">Belum ditentukan</option>
          {tenants.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
      </Field>
      <Field id={`pr-pkg-${p}`} label="Paket yang diminati" optional error={apiErr?.field('package_id')}>
        <select key={tenantId} id={`pr-pkg-${p}`} name="package_id" className="input" defaultValue={prospect?.tenant?.id === tenantId ? prospect?.package?.id ?? '' : ''} disabled={!tenantId}>
          <option value="">{tenantId ? 'Belum ditentukan' : 'Pilih PPIU dulu'}</option>
          {packages.filter((k) => k.tenant_id === tenantId).map((k) => <option key={k.id} value={k.id}>{k.name}</option>)}
        </select>
      </Field>
      <Field id={`pr-date-${p}`} label="Follow-up berikutnya" optional><input id={`pr-date-${p}`} name="next_follow_up_on" type="date" className="input" defaultValue={prospect?.next_follow_up_on ?? ''} min={prospect ? undefined : today()} /></Field>
      {prospect && (
        <Field id={`pr-status-${p}`} label="Status">
          <select id={`pr-status-${p}`} name="follow_up_status" className="input" defaultValue={prospect.follow_up_status}>
            {(Object.keys(FOLLOW_UP) as FollowUpStatus[]).map((s) => <option key={s} value={s}>{FOLLOW_UP[s]}</option>)}
          </select>
        </Field>
      )}
      <div className="sm:col-span-2"><Field id={`pr-notes-${p}`} label="Catatan" optional hint="Mis. rencana berangkat, jumlah keluarga, pertanyaan yang belum dijawab."><textarea id={`pr-notes-${p}`} name="notes" rows={2} className="input" defaultValue={prospect?.notes ?? ''} /></Field></div>
      {apiErr && !apiErr.errors && <div className="sm:col-span-2"><Alert tone="danger">{apiErr.message}</Alert></div>}
      {typeof error === 'string' && <div className="sm:col-span-2"><Alert tone="danger">{error}</Alert></div>}
      <div className="flex gap-2 sm:col-span-2">
        <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}{prospect ? 'Simpan perubahan' : 'Tambah prospek'}</button>
        {onDone && <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={onDone}>Batal</button>}
      </div>
    </form>
  );
}

/** Baris prospek: ubah status cepat, edit, hapus. */
export function ProspectRowActions({ prospect, tenants, packages }: { prospect: Prospect; tenants: Opt[]; packages: PkgOpt[] }) {
  const router = useRouter();
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  if (editing) return <div className="mt-3 rounded-[12px] bg-surface p-4"><ProspectForm tenants={tenants} packages={packages} prospect={prospect} onDone={() => setEditing(false)} /></div>;
  return (
    <div className="flex flex-wrap items-center gap-2">
      <label htmlFor={`st-${prospect.id}`} className="sr-only">Status {prospect.name}</label>
      <select id={`st-${prospect.id}`} className="input min-h-10 w-auto py-1.5 text-sm" defaultValue={prospect.follow_up_status} disabled={busy}
        onChange={async (e) => {
          setBusy(true);
          try { await api(`/agent/prospects/${prospect.id}`, { method: 'PATCH', json: { follow_up_status: e.target.value } }); router.refresh(); }
          catch (err) { alert(errorMessage(err)); }
          setBusy(false);
        }}>
        {(Object.keys(FOLLOW_UP) as FollowUpStatus[]).map((s) => <option key={s} value={s}>{FOLLOW_UP[s]}</option>)}
      </select>
      <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" onClick={() => setEditing(true)}>Ubah</button>
      <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={busy}
        onClick={async () => {
          if (!confirm(`Hapus prospek ${prospect.name}?`)) return;
          setBusy(true);
          try { await api(`/agent/prospects/${prospect.id}`, { method: 'DELETE' }); router.refresh(); }
          catch (err) { alert(errorMessage(err)); }
          setBusy(false);
        }}>Hapus</button>
    </div>
  );
}
