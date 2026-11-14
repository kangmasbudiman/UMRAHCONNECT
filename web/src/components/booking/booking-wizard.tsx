'use client';

import { useRouter } from 'next/navigation';
import { useMemo, useState } from 'react';
import { Alert, Field, Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { ROOM_LABEL, rupiah, tanggal } from '@/lib/format';
import type { SavedPilgrim, WizardDeparture } from '@/lib/booking-types';
import { IconHeading } from '@/components/stat-card';
import { IconCalendarDays, IconCircleCheck, IconTeam } from '@/components/icons';

type Room = 'quad' | 'triple' | 'double';
type NewPilgrim = { full_name: string; nik: string; birth_place: string; birth_date: string; gender: '' | 'L' | 'P'; phone: string; passport_no: string };
type Slot = { room_type: Room; pilgrim_id: string | null; pilgrim: NewPilgrim };

const EMPTY: NewPilgrim = { full_name: '', nik: '', birth_place: '', birth_date: '', gender: '', phone: '', passport_no: '' };
const STEPS = ['Keberangkatan & kamar', 'Data jamaah', 'Tinjau & konfirmasi'];
const STEPS_SHORT = ['Kamar', 'Jamaah', 'Konfirmasi'];

export type WizardProps = {
  mode: 'jamaah' | 'agent' | 'staff';
  endpoint: string;
  /** Tujuan setelah berhasil; "{id}" diganti ID booking (fungsi tidak bisa dioper dari Server Component). */
  successHref: string;
  packageName: string;
  tenantName: string;
  cancellationTerms: string | null;
  departures: WizardDeparture[];
  initialDepartureId?: string | null;
  savedPilgrims?: SavedPilgrim[];
  referral?: { code: string; agentName: string; agentCode: string } | null;
  agents?: { id: string; code: string; display_name: string }[];
  agentNote?: string;
};

/**
 * JMH-03..05 Booking 3 langkah (FR-JMH-02..05). Harga dihitung ulang di server; di sini hanya pratinjau.
 * Satu Idempotency-Key per wizard: klik ganda / koneksi putus tidak membuat booking dobel.
 */
export function BookingWizard(props: WizardProps) {
  const router = useRouter();
  const [step, setStep] = useState(0);
  const sellable = props.departures.filter((d) => d.sales_status === 'open' && d.seats_remaining > 0);
  const [depId, setDepId] = useState<string>(
    props.initialDepartureId && sellable.some((d) => d.id === props.initialDepartureId) ? props.initialDepartureId : sellable[0]?.id ?? '',
  );
  const dep = props.departures.find((d) => d.id === depId);
  const rooms = (dep?.prices ?? []).map((p) => p.room_type);
  const [slots, setSlots] = useState<Slot[]>([{ room_type: (rooms[0] ?? 'quad') as Room, pilgrim_id: null, pilgrim: { ...EMPTY } }]);
  const [booker, setBooker] = useState({ name: '', phone: '' });
  const [agentCode, setAgentCode] = useState('');
  const [agentId, setAgentId] = useState('');
  const [agree, setAgree] = useState(false);
  const [error, setError] = useState<ApiClientError | string | null>(null);
  const [busy, setBusy] = useState(false);
  const [idemKey] = useState(() => crypto.randomUUID());

  const price = (r: Room) => dep?.prices.find((p) => p.room_type === r)?.price_amount ?? 0;
  const total = slots.reduce((a, s) => a + price(s.room_type), 0);
  const dp = dep ? Math.min(total, dep.dp_min_per_pax_amount * slots.length) : 0;
  const maxPax = Math.min(10, dep?.seats_remaining ?? 1);
  const apiErr = error instanceof ApiClientError ? error : null;
  const fieldErr = (i: number, f: string) => apiErr?.field(`participants.${i}.pilgrim.${f}`) ?? apiErr?.field(`participants.${i}.${f}`);

  const usedSaved = useMemo(() => new Set(slots.map((s) => s.pilgrim_id).filter(Boolean)), [slots]);
  const setSlot = (i: number, patch: Partial<Slot>) => setSlots((ss) => ss.map((s, j) => (j === i ? { ...s, ...patch } : s)));
  const setP = (i: number, patch: Partial<NewPilgrim>) => setSlots((ss) => ss.map((s, j) => (j === i ? { ...s, pilgrim: { ...s.pilgrim, ...patch } } : s)));
  const resize = (n: number) => setSlots((ss) => n > ss.length
    ? [...ss, ...Array.from({ length: n - ss.length }, () => ({ room_type: ss[ss.length - 1]?.room_type ?? (rooms[0] as Room), pilgrim_id: null, pilgrim: { ...EMPTY } }))]
    : ss.slice(0, n));

  function validateStep2(): string | null {
    if (props.mode !== 'jamaah' && (!booker.name.trim() || !booker.phone.trim())) return 'Isi nama dan nomor HP pemesan.';
    for (const [i, s] of slots.entries()) {
      if (s.pilgrim_id) continue;
      const p = s.pilgrim;
      if (!p.full_name.trim() || !p.birth_place.trim() || !p.birth_date || !p.gender) return `Lengkapi data jamaah ${i + 1}.`;
      if (p.nik.replace(/\D/g, '').length !== 16) return `NIK jamaah ${i + 1} harus 16 angka.`;
    }
    return null;
  }

  async function submit() {
    if (!agree) { setError('Centang persetujuan syarat paket dan kebijakan pembatalan.'); return; }
    setBusy(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        departure_id: depId,
        accept_package_terms: true,
        participants: slots.map((s) => s.pilgrim_id ? { room_type: s.room_type, pilgrim_id: s.pilgrim_id } : {
          room_type: s.room_type,
          pilgrim: {
            full_name: s.pilgrim.full_name.trim(), nik: s.pilgrim.nik.replace(/\D/g, ''), birth_place: s.pilgrim.birth_place.trim(),
            birth_date: s.pilgrim.birth_date, gender: s.pilgrim.gender, phone: s.pilgrim.phone.trim() || null, passport_no: s.pilgrim.passport_no.trim() || null,
          },
        }),
      };
      if (props.mode === 'jamaah') {
        if (agentCode.trim()) body.agent_code = agentCode.trim();
        else if (props.referral) body.referral_code = props.referral.code;
      } else {
        body.booker = booker;
        if (props.mode === 'staff' && agentId) body.agent_id = agentId;
      }
      const r = await api<{ data: { id: string } }>(props.endpoint, { json: body, headers: { 'Idempotency-Key': idemKey } });
      router.push(props.successHref.replace('{id}', r.data.id));
      router.refresh();
    } catch (e) {
      const err = e instanceof ApiClientError ? e : errorMessage(e);
      setError(err);
      if (err instanceof ApiClientError) {
        if (['seats_unavailable', 'sales_closed', 'departure_unavailable', 'room_type_unavailable'].includes(err.code)) { setStep(0); router.refresh(); }
        else if (err.errors && Object.keys(err.errors).some((k) => k.startsWith('participants') || k.startsWith('booker'))) setStep(1);
        else if (['pilgrim_already_booked', 'pilgrim_duplicate', 'pilgrim_invalid'].includes(err.code)) setStep(1);
      }
      setBusy(false);
    }
  }

  const msg = typeof error === 'string' ? error : error?.message;

  if (sellable.length === 0) {
    return <Alert tone="warning" title="Belum ada keberangkatan yang bisa dipesan">Semua keberangkatan paket ini penuh, ditutup, atau sudah lewat. Hubungi {props.tenantName} untuk daftar tunggu.</Alert>;
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
      <div className="space-y-5">
        <ol className="grid grid-cols-3 gap-2" aria-label="Langkah booking">
          {STEPS.map((l, i) => (
            <li key={l} aria-current={i === step ? 'step' : undefined}
              className={`rounded-[10px] px-3 py-2 text-sm font-semibold ${i === step ? 'bg-primary-700 text-white' : i < step ? 'bg-primary-50 text-primary-800' : 'bg-white text-ink-600 ring-1 ring-border-subtle'}`}>
              <span className="num">{i + 1}.</span> <span className="sm:hidden">{STEPS_SHORT[i]}</span><span className="hidden sm:inline">{l}</span>
            </li>
          ))}
        </ol>
        {msg && <Alert tone="danger">{msg}</Alert>}

        {step === 0 && dep && (
          <section className="card space-y-5 p-5" aria-labelledby="s1">
            <IconHeading icon={IconCalendarDays} id="s1" className="text-xl font-extrabold">Pilih keberangkatan</IconHeading>
            <fieldset className="grid gap-3 sm:grid-cols-2">
              <legend className="sr-only">Keberangkatan</legend>
              {props.departures.map((d) => {
                const ok = d.sales_status === 'open' && d.seats_remaining > 0;
                return (
                  <label key={d.id} className={`flex gap-3 rounded-[12px] border p-4 ${!ok ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'} ${d.id === depId ? 'border-primary-700 bg-primary-50 ring-1 ring-primary-700' : 'border-border-subtle bg-white'}`}>
                    <input type="radio" name="dep" className="mt-1 h-5 w-5 accent-primary-700" checked={d.id === depId} disabled={!ok}
                      onChange={() => { setDepId(d.id); const rs = d.prices.map((p) => p.room_type); setSlots((ss) => ss.slice(0, Math.max(1, Math.min(ss.length, d.seats_remaining))).map((s) => rs.includes(s.room_type) ? s : { ...s, room_type: rs[0] })); }} />
                    <span>
                      <span className="block font-bold">{tanggal(d.departure_date)} – {tanggal(d.return_date)}</span>
                      <span className="block text-sm text-ink-600">Dari {d.departure_city} · mulai {rupiah(Math.min(...d.prices.map((p) => p.price_amount)))}</span>
                      <span className={`mt-1 inline-block text-sm font-semibold ${!ok ? 'text-danger' : d.seats_remaining <= 5 ? 'text-warning' : 'text-success'}`}>
                        {d.sales_status === 'closed' ? 'Penjualan ditutup' : d.seats_remaining <= 0 ? 'Penuh' : `Sisa ${d.seats_remaining} kursi`}
                      </span>
                    </span>
                  </label>
                );
              })}
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-[200px_1fr] sm:items-end">
              <Field id="pax" label="Jumlah jamaah" hint={`Maksimal ${maxPax} orang per booking.`}>
                <div className="flex items-center gap-2">
                  <button type="button" className="h-12 w-12 rounded-[10px] border border-border-input text-xl font-bold disabled:opacity-40" aria-label="Kurangi jamaah" disabled={slots.length <= 1} onClick={() => resize(slots.length - 1)}>−</button>
                  <output id="pax" className="num w-10 text-center text-xl font-extrabold" aria-live="polite">{slots.length}</output>
                  <button type="button" className="h-12 w-12 rounded-[10px] border border-border-input text-xl font-bold disabled:opacity-40" aria-label="Tambah jamaah" disabled={slots.length >= maxPax} onClick={() => resize(slots.length + 1)}>+</button>
                </div>
              </Field>
            </div>
            <fieldset className="space-y-2">
              <legend className="mb-1 font-semibold">Tipe kamar per jamaah</legend>
              {slots.map((s, i) => (
                <div key={i} className="flex flex-wrap items-center gap-3 rounded-[10px] bg-surface px-3 py-2">
                  <span className="w-24 font-semibold">Jamaah {i + 1}</span>
                  <label className="sr-only" htmlFor={`room-${i}`}>Tipe kamar jamaah {i + 1}</label>
                  <select id={`room-${i}`} className="input w-auto min-w-52" value={s.room_type} onChange={(e) => setSlot(i, { room_type: e.target.value as Room })}>
                    {dep.prices.map((p) => <option key={p.room_type} value={p.room_type}>{ROOM_LABEL[p.room_type]} · {rupiah(p.price_amount)}</option>)}
                  </select>
                </div>
              ))}
              <p className="text-sm text-ink-600">Pembagian kamar akhir diatur PPIU sesuai jenis kelamin dan mahram.</p>
            </fieldset>
            <button type="button" className="btn btn-primary" onClick={() => { setError(null); setStep(1); }}>Lanjut ke data jamaah</button>
          </section>
        )}

        {step === 1 && (
          <section className="space-y-4" aria-labelledby="s2">
            <IconHeading icon={IconTeam} id="s2" className="text-xl font-extrabold">Data jamaah</IconHeading>
            {props.mode !== 'jamaah' && (
              <div className="card grid gap-4 p-5 sm:grid-cols-2">
                <p className="font-bold sm:col-span-2">Pemesan</p>
                <Field id="bk-name" label="Nama pemesan" error={apiErr?.field('booker.name')}>
                  <input id="bk-name" className="input" value={booker.name} onChange={(e) => setBooker({ ...booker, name: e.target.value })} required />
                </Field>
                <Field id="bk-phone" label="Nomor HP/WhatsApp pemesan" hint="Kode booking dan invoice dikirim ke nomor ini." error={apiErr?.field('booker.phone')}>
                  <input id="bk-phone" className="input" inputMode="tel" value={booker.phone} onChange={(e) => setBooker({ ...booker, phone: e.target.value })} required />
                </Field>
              </div>
            )}
            {slots.map((s, i) => (
              <div key={i} className="card space-y-4 p-5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-bold">Jamaah {i + 1} · <span className="font-normal text-ink-600">{ROOM_LABEL[s.room_type]}</span></p>
                  {props.savedPilgrims && props.savedPilgrims.length > 0 && (
                    <div>
                      <label className="sr-only" htmlFor={`saved-${i}`}>Pilih data tersimpan untuk jamaah {i + 1}</label>
                      <select id={`saved-${i}`} className="input w-auto min-w-56" value={s.pilgrim_id ?? ''} onChange={(e) => setSlot(i, { pilgrim_id: e.target.value || null })}>
                        <option value="">Isi data baru</option>
                        {props.savedPilgrims.map((p) => <option key={p.id} value={p.id} disabled={usedSaved.has(p.id) && s.pilgrim_id !== p.id}>{p.full_name} · {p.nik_masked}</option>)}
                      </select>
                    </div>
                  )}
                </div>
                {s.pilgrim_id ? (
                  <p className="rounded-[10px] bg-primary-50 px-3 py-2 text-sm">Memakai data tersimpan: <span className="font-bold">{props.savedPilgrims?.find((p) => p.id === s.pilgrim_id)?.full_name}</span></p>
                ) : (
                  <div className="grid gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2">
                      <Field id={`fn-${i}`} label="Nama lengkap sesuai paspor/KTP" error={fieldErr(i, 'full_name')}>
                        <input id={`fn-${i}`} className="input" value={s.pilgrim.full_name} onChange={(e) => setP(i, { full_name: e.target.value })} autoComplete="off" />
                      </Field>
                    </div>
                    <Field id={`nik-${i}`} label="NIK" hint="16 angka sesuai KTP" error={fieldErr(i, 'nik')}>
                      <input id={`nik-${i}`} className="input num tracking-wider" inputMode="numeric" maxLength={19} value={s.pilgrim.nik} onChange={(e) => setP(i, { nik: e.target.value })} autoComplete="off" />
                    </Field>
                    <Field id={`gd-${i}`} label="Jenis kelamin" error={fieldErr(i, 'gender')}>
                      <select id={`gd-${i}`} className="input" value={s.pilgrim.gender} onChange={(e) => setP(i, { gender: e.target.value as 'L' | 'P' })}>
                        <option value="">Pilih</option><option value="L">Laki-laki</option><option value="P">Perempuan</option>
                      </select>
                    </Field>
                    <Field id={`bp-${i}`} label="Tempat lahir" error={fieldErr(i, 'birth_place')}>
                      <input id={`bp-${i}`} className="input" value={s.pilgrim.birth_place} onChange={(e) => setP(i, { birth_place: e.target.value })} />
                    </Field>
                    <Field id={`bd-${i}`} label="Tanggal lahir" error={fieldErr(i, 'birth_date')}>
                      <input id={`bd-${i}`} type="date" className="input" value={s.pilgrim.birth_date} onChange={(e) => setP(i, { birth_date: e.target.value })} />
                    </Field>
                    <Field id={`ph-${i}`} label="Nomor HP jamaah" optional error={fieldErr(i, 'phone')}>
                      <input id={`ph-${i}`} className="input" inputMode="tel" value={s.pilgrim.phone} onChange={(e) => setP(i, { phone: e.target.value })} />
                    </Field>
                    <Field id={`pp-${i}`} label="Nomor paspor" optional hint="Bisa dilengkapi nanti." error={fieldErr(i, 'passport_no')}>
                      <input id={`pp-${i}`} className="input uppercase" value={s.pilgrim.passport_no} onChange={(e) => setP(i, { passport_no: e.target.value })} />
                    </Field>
                  </div>
                )}
              </div>
            ))}
            <p className="text-sm text-ink-600">NIK dan paspor disimpan terenkripsi dan hanya dapat dibuka PPIU penyelenggara.</p>
            <div className="flex flex-wrap gap-2">
              <button type="button" className="btn btn-outline" onClick={() => setStep(0)}>Kembali</button>
              <button type="button" className="btn btn-primary" onClick={() => { const e = validateStep2(); setError(e); if (!e) setStep(2); }}>Lanjut ke tinjauan</button>
            </div>
          </section>
        )}

        {step === 2 && dep && (
          <section className="card space-y-5 p-5" aria-labelledby="s3">
            <IconHeading icon={IconCircleCheck} id="s3" className="text-xl font-extrabold">Tinjau dan konfirmasi</IconHeading>
            <dl className="grid gap-3 sm:grid-cols-2">
              <div><dt className="text-sm text-ink-600">Paket</dt><dd className="font-bold">{props.packageName}</dd></div>
              <div><dt className="text-sm text-ink-600">Keberangkatan</dt><dd className="font-bold">{tanggal(dep.departure_date)} dari {dep.departure_city}</dd></div>
              {props.mode !== 'jamaah' && <div><dt className="text-sm text-ink-600">Pemesan</dt><dd className="font-bold">{booker.name} · {booker.phone}</dd></div>}
            </dl>
            <ul className="divide-y divide-border-subtle rounded-[12px] border border-border-subtle">
              {slots.map((s, i) => (
                <li key={i} className="flex justify-between gap-3 px-4 py-2">
                  <span>{s.pilgrim_id ? props.savedPilgrims?.find((p) => p.id === s.pilgrim_id)?.full_name : s.pilgrim.full_name} <span className="text-sm text-ink-600">· {ROOM_LABEL[s.room_type]}</span></span>
                  <span className="num font-semibold">{rupiah(price(s.room_type))}</span>
                </li>
              ))}
            </ul>

            {props.mode === 'jamaah' && (
              <div className="rounded-[12px] bg-surface p-4">
                {props.referral && !agentCode.trim() && <p className="mb-2 text-sm">Dibantu agen <span className="font-bold">{props.referral.agentName}</span> ({props.referral.agentCode}) dari link yang Anda buka.</p>}
                <Field id="agent-code" label="Kode agen" optional hint="Isi bila Anda dibantu agen yang memberi kode, mis. BUDI24. Harga tetap sama." error={apiErr?.field('agent_code')}>
                  <input id="agent-code" className="input uppercase sm:max-w-56" maxLength={16} value={agentCode} onChange={(e) => setAgentCode(e.target.value)} />
                </Field>
              </div>
            )}
            {props.mode === 'staff' && props.agents && props.agents.length > 0 && (
              <Field id="agent-id" label="Agen yang membantu" optional hint="Pilih bila jamaah datang lewat agen mitra; komisi agen dihitung dari skema yang berlaku." error={apiErr?.field('agent_id')}>
                <select id="agent-id" className="input sm:max-w-80" value={agentId} onChange={(e) => setAgentId(e.target.value)}>
                  <option value="">Tanpa agen</option>
                  {props.agents.map((a) => <option key={a.id} value={a.id}>{a.display_name} ({a.code})</option>)}
                </select>
              </Field>
            )}
            {props.agentNote && <p className="text-sm text-ink-600">{props.agentNote}</p>}

            {props.cancellationTerms && (
              <details className="rounded-[12px] border border-border-subtle p-3">
                <summary className="min-h-10 cursor-pointer font-semibold">Kebijakan pembatalan {props.tenantName}</summary>
                <p className="mt-2 whitespace-pre-line text-ink-600">{props.cancellationTerms}</p>
              </details>
            )}
            <label className="flex gap-3 leading-6">
              <input type="checkbox" className="mt-1 h-5 w-5 shrink-0 accent-primary-700" checked={agree} onChange={(e) => setAgree(e.target.checked)} />
              <span>{props.mode === 'jamaah' ? 'Saya' : 'Pemesan'} menyetujui syarat paket dan kebijakan pembatalan {props.tenantName}, serta memahami bahwa pembayaran ditransfer langsung ke rekening resmi PPIU.</span>
            </label>
            <div className="flex flex-wrap gap-2">
              <button type="button" className="btn btn-outline" onClick={() => setStep(1)} disabled={busy}>Kembali</button>
              <button type="button" className="btn btn-gold" onClick={submit} disabled={busy}>{busy && <Spinner />}Konfirmasi booking</button>
            </div>
          </section>
        )}
      </div>

      {dep && (
        <aside className="lg:sticky lg:top-24 lg:self-start">
          <div className="card overflow-hidden">
            <div className="bg-hero p-4 text-white">
              <p className="text-sm text-white/85">{props.tenantName}</p>
              <p className="font-extrabold">{props.packageName}</p>
              <p className="text-sm text-white/85">{tanggal(dep.departure_date)} · {dep.departure_city}</p>
            </div>
            <dl className="space-y-2 p-4">
              <div className="flex justify-between"><dt className="text-ink-600">{slots.length} jamaah</dt><dd className="num font-semibold">{rupiah(total)}</dd></div>
              <div className="flex justify-between border-t border-dashed border-border-subtle pt-2"><dt className="font-bold">Total</dt><dd className="num text-xl font-extrabold text-primary-800">{rupiah(total)}</dd></div>
              <div className="rounded-[10px] bg-gold-50 p-3 text-sm">
                <p><span className="font-bold">DP {rupiah(dp)}</span> dibayar paling lambat {dep.dp_deadline_hours ?? 48} jam setelah booking; kursi ditahan selama itu.</p>
                <p className="mt-1 text-ink-600">Pelunasan paling lambat {tanggal(dep.final_payment_due_on)}.</p>
              </div>
              <p className="text-xs text-ink-600">Harga resmi PPIU. Total akhir dihitung ulang sistem saat konfirmasi.</p>
            </dl>
          </div>
        </aside>
      )}
    </div>
  );
}
