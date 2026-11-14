'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { EARN_ON, type Partnership, type Scheme } from '@/lib/agent-types';

type Err = ApiClientError | string | null;

/** Input komisi: flat rupiah per jamaah atau persen (basis poin), plus kapan diperoleh. */
function SchemeFields({ prefix, defaultType = 'flat' }: { prefix: string; defaultType?: 'flat' | 'percent' }) {
  const [type, setType] = useState<'flat' | 'percent'>(defaultType);
  return (
    <div className="grid gap-3 sm:grid-cols-3">
      <Field id={`${prefix}-type`} label="Jenis komisi">
        <select id={`${prefix}-type`} name="type" className="input" value={type} onChange={(e) => setType(e.target.value as 'flat' | 'percent')}>
          <option value="flat">Nominal per jamaah</option>
          <option value="percent">Persen dari harga</option>
        </select>
      </Field>
      {type === 'flat' ? (
        <Field id={`${prefix}-amount`} label="Nominal (Rp per jamaah)"><input id={`${prefix}-amount`} name="flat_amount" inputMode="numeric" className="input num" required placeholder="1000000" /></Field>
      ) : (
        <Field id={`${prefix}-pct`} label="Persen (%)" hint="Contoh: 2,5"><input id={`${prefix}-pct`} name="percent" inputMode="decimal" className="input num" required placeholder="2,5" /></Field>
      )}
      <Field id={`${prefix}-earn`} label="Diperoleh">
        <select id={`${prefix}-earn`} name="earn_on" className="input" defaultValue="fully_paid">
          <option value="fully_paid">Saat booking lunas</option>
          <option value="dp_verified">Saat DP terverifikasi</option>
        </select>
      </Field>
    </div>
  );
}

function readScheme(fd: FormData) {
  const type = String(fd.get('type'));
  const amount = String(fd.get('flat_amount') ?? '').replace(/\D/g, '');
  const pct = String(fd.get('percent') ?? '').replace(',', '.');
  return {
    type,
    flat_amount: type === 'flat' ? (amount ? Number(amount) : null) : null,
    percent_bp: type === 'percent' ? (pct ? Math.round(Number(pct) * 100) : null) : null,
    earn_on: String(fd.get('earn_on') ?? 'fully_paid'),
  };
}

/** Aksi kemitraan per baris: setujui (dengan skema), tolak, tangguhkan, aktifkan, akhiri. */
export function PartnershipRowActions({ tenantId, p, defaultLabel }: { tenantId: string; p: Partnership; defaultLabel: string | null }) {
  const router = useRouter();
  const [mode, setMode] = useState<'approve' | null>(null);
  const [useCustom, setUseCustom] = useState(!defaultLabel);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const base = `/tenants/${tenantId}/partnerships/${p.id}`;
  const call = async (key: string, path: string, json?: object) => {
    setBusy(key);
    setError(null);
    try { await api(`${base}/${path}`, { method: 'POST', json }); setMode(null); router.refresh(); } catch (e) { setError(errorMessage(e)); }
    setBusy(null);
  };
  const withReason = (key: string, path: string, q: string) => {
    const reason = prompt(q);
    if (reason && reason.trim().length >= 3) call(key, path, { reason: reason.trim() });
  };

  if (mode === 'approve') {
    return (
      <form className="w-full space-y-3 rounded-[12px] bg-surface p-3" onSubmit={(e) => {
        e.preventDefault();
        call('approve', 'approve', useCustom ? { new_scheme: readScheme(new FormData(e.currentTarget)) } : {});
      }}>
        <fieldset className="space-y-2">
          <legend className="font-semibold">Komisi untuk {p.agent.display_name}</legend>
          {defaultLabel
            ? <label className="flex min-h-10 items-center gap-2"><input type="radio" name="mode" checked={!useCustom} onChange={() => setUseCustom(false)} className="h-5 w-5 accent-primary-700" />Skema default PPIU <span className="text-ink-600">({defaultLabel})</span></label>
            : <p className="text-sm text-ink-600">PPIU belum punya skema default. Isi komisi untuk agen ini, atau buat skema default di panel Skema komisi.</p>}
          <label className="flex min-h-10 items-center gap-2"><input type="radio" name="mode" checked={useCustom} onChange={() => setUseCustom(true)} className="h-5 w-5 accent-primary-700" />Skema khusus agen ini</label>
        </fieldset>
        {useCustom && <SchemeFields prefix={`ap-${p.id}`} />}
        {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
        <div className="flex gap-2">
          <button className="btn btn-primary min-h-10 px-4" disabled={!!busy}>{busy === 'approve' && <Spinner />}Setujui kemitraan</button>
          <button type="button" className="btn min-h-10 px-3 text-primary-700 hover:bg-primary-50" onClick={() => setMode(null)}>Batal</button>
        </div>
      </form>
    );
  }
  return (
    <div className="flex flex-wrap items-center gap-2">
      {p.status === 'applied' && <>
        <button type="button" className="btn btn-primary min-h-10 px-4" disabled={!!busy} onClick={() => setMode('approve')}>Setujui</button>
        <button type="button" className="btn btn-outline min-h-10 px-4" disabled={!!busy} onClick={() => withReason('reject', 'reject', `Alasan menolak ${p.agent.display_name} (ditampilkan ke agen):`)}>Tolak</button>
      </>}
      {p.status === 'active' && <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" disabled={!!busy} onClick={() => withReason('suspend', 'suspend', `Alasan menangguhkan ${p.agent.display_name}? Link referralnya berhenti mengatribusi sementara.`)}>Tangguhkan</button>}
      {p.status === 'suspended' && <button type="button" className="btn btn-primary min-h-10 px-3 text-sm" disabled={!!busy} onClick={() => call('reactivate', 'reactivate')}>{busy === 'reactivate' && <Spinner />}Aktifkan lagi</button>}
      {['active', 'suspended', 'invited'].includes(p.status) && (
        <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
          onClick={() => withReason('end', 'end', p.status === 'invited' ? 'Alasan membatalkan undangan:' : `Alasan mengakhiri kemitraan dengan ${p.agent.display_name}? Komisi yang sudah diperoleh tetap harus dibayar.`)}>
          {p.status === 'invited' ? 'Batalkan undangan' : 'Akhiri'}
        </button>
      )}
      {error && <p role="alert" className="w-full text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}

/** Undang agen yang sudah terdaftar lewat nomor HP atau email (FR-PPIU-09). */
export function InviteAgentForm({ tenantId, hasDefault }: { tenantId: string; hasDefault: boolean }) {
  const router = useRouter();
  const [error, setError] = useState<Err>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <form className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const form = e.currentTarget;
      const v = String(new FormData(form).get('contact') ?? '').trim();
      setBusy(true);
      setError(null);
      setDone(null);
      try {
        const r = await api<{ data: Partnership }>(`/tenants/${tenantId}/partnerships/invitations`, { json: v.includes('@') ? { email: v } : { phone: v } });
        setDone(`Undangan terkirim ke ${r.data.agent.display_name} (${r.data.agent.code}).`);
        form.reset();
        router.refresh();
      } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
      setBusy(false);
    }}>
      {!hasDefault && <div className="sm:col-span-2"><Alert tone="warning">Buat skema komisi default dulu agar agen yang diundang langsung tahu komisinya.</Alert></div>}
      <Field id="inv-agent" label="Undang agen" hint="Nomor HP atau email agen yang sudah terdaftar di UMRAH CONNECT." error={apiErr?.field('phone') ?? apiErr?.field('email')}>
        <input id="inv-agent" name="contact" className="input" required placeholder="0812… atau nama@contoh.com" />
      </Field>
      <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Kirim undangan</button>
      {apiErr && !apiErr.errors && <div className="sm:col-span-2"><Alert tone="danger">{apiErr.message}</Alert></div>}
      {apiErr?.code === 'agent_not_registered' && <p className="text-sm text-ink-600 sm:col-span-2">{apiErr.message}</p>}
      {typeof error === 'string' && <div className="sm:col-span-2"><Alert tone="danger">{error}</Alert></div>}
      {done && <div className="sm:col-span-2"><Alert tone="success">{done}</Alert></div>}
    </form>
  );
}

/** Skema komisi: buat skema default / per paket / per agen; skema lama otomatis ditutup (BR-06). */
export function SchemeManager({ tenantId, schemes, packages, agents, canManage }: {
  tenantId: string; schemes: Scheme[]; packages: { id: string; name: string }[]; agents: { id: string; code: string; display_name: string }[]; canManage: boolean;
}) {
  const router = useRouter();
  const [scope, setScope] = useState<Scheme['scope']>('default');
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const active = schemes.filter((s) => !s.effective_to);
  const past = schemes.filter((s) => s.effective_to);
  const SCOPE: Record<Scheme['scope'], string> = { default: 'Default semua agen', package: 'Per paket', agent: 'Per agen', agent_package: 'Agen + paket' };
  const target = (s: Scheme) => [s.agent ? `${s.agent.display_name} (${s.agent.code})` : null, s.package_name].filter(Boolean).join(' · ') || 'Semua agen & paket';

  return (
    <div className="space-y-4">
      <p className="text-sm text-ink-600">Urutan berlaku: agen + paket → agen → paket → default. Mengubah skema tidak mengubah komisi yang sudah diperoleh.</p>
      <ul className="divide-y divide-border-subtle">
        {active.map((s) => (
          <li key={s.id} className="flex flex-wrap items-center gap-3 py-3">
            <span className="rounded-full bg-primary-50 px-2.5 py-0.5 text-sm font-semibold text-primary-800">{SCOPE[s.scope]}</span>
            <div className="min-w-0 flex-1">
              <p className="font-bold">{s.label}</p>
              <p className="text-sm text-ink-600">{target(s)} · diperoleh {EARN_ON[s.earn_on]}</p>
            </div>
            {canManage && s.scope !== 'default' && (
              <button type="button" className="min-h-10 px-2 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
                onClick={async () => {
                  if (!confirm('Akhiri skema ini? Agen kembali memakai skema di bawahnya (mis. default).')) return;
                  setBusy(s.id);
                  try { await api(`/tenants/${tenantId}/commission-schemes/${s.id}/end`, { method: 'POST' }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
                  setBusy(null);
                }}>Akhiri</button>
            )}
          </li>
        ))}
      </ul>
      {typeof error === 'string' && <Alert tone="danger">{error}</Alert>}
      {canManage && (open ? (
        <form className="space-y-3 rounded-[12px] bg-surface p-4" noValidate onSubmit={async (e) => {
          e.preventDefault();
          const fd = new FormData(e.currentTarget);
          setBusy('new');
          setError(null);
          try {
            await api(`/tenants/${tenantId}/commission-schemes`, { json: {
              scope, package_id: ['package', 'agent_package'].includes(scope) ? fd.get('package_id') : null,
              agent_id: ['agent', 'agent_package'].includes(scope) ? fd.get('agent_id') : null, ...readScheme(fd),
            } });
            setOpen(false);
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
          setBusy(null);
        }}>
          <div className="grid gap-3 sm:grid-cols-3">
            <Field id="sc-scope" label="Berlaku untuk">
              <select id="sc-scope" className="input" value={scope} onChange={(e) => setScope(e.target.value as Scheme['scope'])}>
                {Object.entries(SCOPE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </Field>
            {['agent', 'agent_package'].includes(scope) && (
              <Field id="sc-agent" label="Agen">
                <select id="sc-agent" name="agent_id" className="input" required>{agents.map((a) => <option key={a.id} value={a.id}>{a.display_name} ({a.code})</option>)}</select>
              </Field>
            )}
            {['package', 'agent_package'].includes(scope) && (
              <Field id="sc-pkg" label="Paket">
                <select id="sc-pkg" name="package_id" className="input" required>{packages.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
              </Field>
            )}
          </div>
          <SchemeFields prefix="sc" />
          {error instanceof ApiClientError && <Alert tone="danger">{error.errors ? Object.values(error.errors)[0]?.[0] : error.message}</Alert>}
          <div className="flex gap-2">
            <button className="btn btn-primary" disabled={busy === 'new'}>{busy === 'new' && <Spinner />}Simpan skema</button>
            <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={() => setOpen(false)}>Batal</button>
          </div>
        </form>
      ) : <button type="button" className="btn btn-outline" onClick={() => setOpen(true)}>+ Skema komisi baru</button>)}
      {past.length > 0 && (
        <details className="rounded-[12px] border border-border-subtle p-3">
          <summary className="min-h-10 cursor-pointer font-semibold">Riwayat skema ({past.length})</summary>
          <ul className="mt-2 space-y-1 text-sm text-ink-600">
            {past.map((s) => <li key={s.id}>{SCOPE[s.scope]} · {target(s)}: {s.label} · berakhir {new Date(s.effective_to!).toLocaleDateString('id-ID', { timeZone: 'Asia/Jakarta' })}</li>)}
          </ul>
        </details>
      )}
    </div>
  );
}
