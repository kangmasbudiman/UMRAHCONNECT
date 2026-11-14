'use client';

import Image from 'next/image';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Alert, Field, Spinner, StatusPill } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';
import { ROOM_LABEL, rupiah, tanggal } from '@/lib/format';
import { PUBLISH_ITEMS, type DepartureManaged, type ItineraryDay, type PackageManaged, type RoomType } from '@/lib/tenant-types';

type Err = ApiClientError | string | null;
const msgOf = (e: Err) => (typeof e === 'string' ? e : e?.message);
const lines = (v: FormDataEntryValue | null) => String(v ?? '').split('\n').map((x) => x.trim()).filter(Boolean);
const numOrNull = (v: FormDataEntryValue | null) => { const s = String(v ?? '').replace(/\D/g, ''); return s ? Number(s) : null; };
const strOrNull = (v: FormDataEntryValue | null) => { const s = String(v ?? '').trim(); return s || null; };

/** PPIU-05 Buat paket: data dasar saja, lalu masuk editor. */
export function NewPackageForm({ tenantId }: { tenantId: string }) {
  const router = useRouter();
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;
  return (
    <form className="grid gap-4 md:grid-cols-2" noValidate onSubmit={async (e) => {
      e.preventDefault();
      const fd = new FormData(e.currentTarget);
      setBusy(true);
      setError(null);
      try {
        const r = await api<{ data: { id: string } }>(`/tenants/${tenantId}/packages`, {
          json: { name: fd.get('name'), duration_days: numOrNull(fd.get('duration_days')), airline: fd.get('airline') },
        });
        router.replace(`/dasbor/ppiu/${tenantId}/paket/${r.data.id}?baru=1`);
      } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); setBusy(false); }
    }}>
      {msgOf(error) && !apiErr?.errors && <div className="md:col-span-2"><Alert tone="danger">{msgOf(error)}</Alert></div>}
      <div className="md:col-span-2">
        <Field id="p-name" label="Nama paket" hint="Contoh: Umrah Reguler Desember 12 Hari" error={apiErr?.field('name')}>
          <input id="p-name" name="name" className="input" required maxLength={200} autoFocus />
        </Field>
      </div>
      <Field id="p-days" label="Durasi (hari)" error={apiErr?.field('duration_days')}>
        <input id="p-days" name="duration_days" type="number" min={1} max={60} className="input num" required defaultValue={12} />
      </Field>
      <Field id="p-airline" label="Maskapai" error={apiErr?.field('airline')}>
        <input id="p-airline" name="airline" className="input" list="airlines" required />
      </Field>
      <datalist id="airlines">{['Saudia', 'Garuda Indonesia', 'Lion Air', 'Batik Air', 'Emirates', 'Qatar Airways', 'Oman Air', 'Etihad'].map((a) => <option key={a} value={a} />)}</datalist>
      <div className="md:col-span-2"><button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Simpan draf & lanjutkan</button></div>
    </form>
  );
}

/** Tombol status paket: terbit / tarik / salin / arsipkan (BP-02). */
export function PackageActions({ tenantId, pkg, tenantSlug, canManage, canPublish, tenantVerified }: {
  tenantId: string; pkg: PackageManaged; tenantSlug: string; canManage: boolean; canPublish: boolean; tenantVerified: boolean;
}) {
  const router = useRouter();
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const base = `/tenants/${tenantId}/packages/${pkg.id}`;
  const run = async (key: string, fn: () => Promise<void>) => {
    setBusy(key);
    setError(null);
    try { await fn(); } catch (e) { setError(e instanceof ApiClientError ? e : errorMessage(e)); }
    setBusy(null);
  };
  const check = pkg.publish_checklist;
  const missing = check ? Object.entries(check.items).filter(([, ok]) => !ok).map(([k]) => PUBLISH_ITEMS[k] ?? k) : [];
  const editable = ['draft', 'published'].includes(pkg.status);

  return (
    <div className="card space-y-4 p-5">
      <div className="flex items-center justify-between gap-2">
        <h2 className="text-lg font-extrabold">Status</h2>
        <StatusPill status={pkg.status} />
      </div>
      {pkg.status === 'published' && (
        <p className="text-sm text-ink-600">Tampil di marketplace. <a href={`/paket/${tenantSlug}/${pkg.slug}`} target="_blank" rel="noopener" className="font-semibold text-primary-700 underline">Lihat halaman publik</a></p>
      )}
      {pkg.status === 'taken_down' && <Alert tone="danger" title="Diturunkan oleh tim UMRAH CONNECT">{pkg.taken_down_reason}</Alert>}
      {(pkg.status === 'draft' || pkg.status === 'archived') && check && (
        <div>
          <p className="font-semibold">Syarat terbit</p>
          <ul className="mt-2 space-y-1.5">
            {Object.entries(check.items).map(([k, ok]) => (
              <li key={k} className="flex items-start gap-2 text-sm">
                <span aria-hidden="true" className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold ${ok ? 'bg-success text-white' : 'border-2 border-border-input'}`}>{ok ? '✓' : ''}</span>
                <span className={ok ? '' : 'text-ink-600'}>{PUBLISH_ITEMS[k] ?? k}<span className="sr-only">{ok ? ' (lengkap)' : ' (belum)'}</span></span>
              </li>
            ))}
          </ul>
          {!check.has_images && <p className="mt-2 text-sm text-ink-600">Saran: tambahkan foto agar paket lebih menarik. Tanpa foto, sampul memakai gambar bawaan.</p>}
        </div>
      )}
      {msgOf(error) && <Alert tone="danger">{msgOf(error)}</Alert>}
      <div className="grid gap-2">
        {canPublish && (pkg.status === 'draft' || pkg.status === 'archived') && (
          <button type="button" className="btn btn-gold" disabled={!!busy || !tenantVerified || missing.length > 0}
            onClick={() => run('publish', async () => { await api(`${base}/publish`, { method: 'POST' }); router.refresh(); })}>
            {busy === 'publish' && <Spinner />}Terbitkan ke marketplace
          </button>
        )}
        {canPublish && !tenantVerified && pkg.status === 'draft' && <p className="text-sm text-ink-600">Terbit tersedia setelah PPIU terverifikasi.</p>}
        {canPublish && pkg.status === 'published' && (
          <button type="button" className="btn btn-outline" disabled={!!busy}
            onClick={() => { if (confirm('Tarik paket dari marketplace? Booking yang sudah ada tetap berjalan.')) run('unpublish', async () => { await api(`${base}/unpublish`, { method: 'POST' }); router.refresh(); }); }}>
            {busy === 'unpublish' && <Spinner />}Tarik dari marketplace
          </button>
        )}
        {canManage && (
          <button type="button" className="btn text-primary-700 hover:bg-primary-50" disabled={!!busy}
            onClick={() => run('dup', async () => { const r = await api<{ data: { id: string } }>(`${base}/duplicate`, { method: 'POST' }); router.push(`/dasbor/ppiu/${tenantId}/paket/${r.data.id}`); })}>
            {busy === 'dup' && <Spinner />}Salin jadi paket baru
          </button>
        )}
        {canManage && editable && (
          <button type="button" className="min-h-12 text-sm font-semibold text-danger hover:underline" disabled={!!busy}
            onClick={() => { if (confirm('Arsipkan paket? Paket hilang dari marketplace dan tidak bisa diubah lagi (bisa disalin).')) run('archive', async () => { await api(`${base}/archive`, { method: 'POST' }); router.refresh(); }); }}>
            Arsipkan paket
          </button>
        )}
      </div>
    </div>
  );
}

/**
 * PPIU-05 Editor paket: semua isi paket dalam satu form, disimpan dengan If-Match (ETag).
 * Form tidak di-remount setelah simpan (pesan sukses tetap tampil); ETag terbaru datang lewat props saat router.refresh().
 */
export function PackageDetailsForm({ tenantId, pkg, readOnly }: { tenantId: string; pkg: PackageManaged; readOnly: boolean }) {
  const router = useRouter();
  const [days, setDays] = useState<ItineraryDay[]>(pkg.itinerary.length ? pkg.itinerary : [{ day: 1, title: '', activities: [] }]);
  const [error, setError] = useState<Err>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const body = {
      name: fd.get('name'), duration_days: numOrNull(fd.get('duration_days')), airline: fd.get('airline'),
      hotel_makkah: strOrNull(fd.get('hotel_makkah')), hotel_makkah_stars: numOrNull(fd.get('hotel_makkah_stars')), hotel_makkah_distance_m: numOrNull(fd.get('hotel_makkah_distance_m')),
      hotel_madinah: strOrNull(fd.get('hotel_madinah')), hotel_madinah_stars: numOrNull(fd.get('hotel_madinah_stars')), hotel_madinah_distance_m: numOrNull(fd.get('hotel_madinah_distance_m')),
      description: strOrNull(fd.get('description')),
      facilities: lines(fd.get('facilities')), includes: lines(fd.get('includes')), excludes: lines(fd.get('excludes')),
      itinerary: days.filter((d) => d.title.trim()).map((d, i) => ({ day: i + 1, title: d.title.trim(), activities: d.activities.map((a) => a.trim()).filter(Boolean) })),
      document_requirements: strOrNull(fd.get('document_requirements')), payment_terms: strOrNull(fd.get('payment_terms')), cancellation_terms: strOrNull(fd.get('cancellation_terms')),
    };
    setBusy(true);
    setError(null);
    setSaved(false);
    try {
      await api(`/tenants/${tenantId}/packages/${pkg.id}`, { method: 'PATCH', json: body, headers: { 'If-Match': pkg.etag } });
      setSaved(true);
      router.refresh();
    } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
    setBusy(false);
  }

  const setDay = (i: number, patch: Partial<ItineraryDay>) => setDays((ds) => ds.map((d, j) => (j === i ? { ...d, ...patch } : d)));
  const stars = (name: string, value: number | null) => (
    <select id={name} name={name} className="input" defaultValue={value ?? ''}>
      <option value="">—</option>{[5, 4, 3, 2, 1].map((n) => <option key={n} value={n}>Bintang {n}</option>)}
    </select>
  );
  const section = 'space-y-4 border-t border-border-subtle pt-5';

  return (
    <form onSubmit={submit} noValidate className="space-y-5">
      <fieldset disabled={readOnly} className="space-y-5">
        <div className="grid gap-4 md:grid-cols-3">
          <div className="md:col-span-3"><Field id="name" label="Nama paket" error={apiErr?.field('name')}><input id="name" name="name" className="input" defaultValue={pkg.name} required /></Field></div>
          <Field id="duration_days" label="Durasi (hari)" error={apiErr?.field('duration_days')}><input id="duration_days" name="duration_days" type="number" min={1} max={60} className="input num" defaultValue={pkg.duration_days} /></Field>
          <div className="md:col-span-2"><Field id="airline" label="Maskapai" error={apiErr?.field('airline')}><input id="airline" name="airline" className="input" defaultValue={pkg.airline} /></Field></div>
          <div className="md:col-span-3">
            <Field id="description" label="Deskripsi" hint="Ringkas: untuk siapa paket ini, keunggulan, pembimbing." error={apiErr?.field('description')}>
              <textarea id="description" name="description" rows={4} className="input" defaultValue={pkg.description ?? ''} />
            </Field>
          </div>
        </div>

        <div className={section}>
          <h3 className="text-lg font-extrabold">Hotel</h3>
          <div className="grid gap-4 md:grid-cols-[2fr_1fr_1fr]">
            <Field id="hotel_makkah" label="Hotel Makkah"><input id="hotel_makkah" name="hotel_makkah" className="input" defaultValue={pkg.hotel_makkah ?? ''} /></Field>
            <Field id="hotel_makkah_stars" label="Kelas">{stars('hotel_makkah_stars', pkg.hotel_makkah_stars)}</Field>
            <Field id="hotel_makkah_distance_m" label="Jarak ke Masjidil Haram (m)"><input id="hotel_makkah_distance_m" name="hotel_makkah_distance_m" inputMode="numeric" className="input num" defaultValue={pkg.hotel_makkah_distance_m ?? ''} /></Field>
            <Field id="hotel_madinah" label="Hotel Madinah"><input id="hotel_madinah" name="hotel_madinah" className="input" defaultValue={pkg.hotel_madinah ?? ''} /></Field>
            <Field id="hotel_madinah_stars" label="Kelas">{stars('hotel_madinah_stars', pkg.hotel_madinah_stars)}</Field>
            <Field id="hotel_madinah_distance_m" label="Jarak ke Masjid Nabawi (m)"><input id="hotel_madinah_distance_m" name="hotel_madinah_distance_m" inputMode="numeric" className="input num" defaultValue={pkg.hotel_madinah_distance_m ?? ''} /></Field>
          </div>
        </div>

        <div className={section}>
          <h3 className="text-lg font-extrabold">Fasilitas dan biaya</h3>
          <p className="text-sm text-ink-600">Satu poin per baris.</p>
          <div className="grid gap-4 md:grid-cols-3">
            <Field id="includes" label="Termasuk"><textarea id="includes" name="includes" rows={6} className="input" defaultValue={pkg.includes.join('\n')} placeholder={'Tiket pesawat PP\nVisa umrah\nHotel'} /></Field>
            <Field id="excludes" label="Tidak termasuk"><textarea id="excludes" name="excludes" rows={6} className="input" defaultValue={pkg.excludes.join('\n')} placeholder={'Pembuatan paspor\nVaksin meningitis'} /></Field>
            <Field id="facilities" label="Fasilitas"><textarea id="facilities" name="facilities" rows={6} className="input" defaultValue={pkg.facilities.join('\n')} placeholder={'Muthawwif berbahasa Indonesia\nManasik 2 kali'} /></Field>
          </div>
        </div>

        <div className={section}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-lg font-extrabold">Rencana perjalanan</h3>
            <p className="text-sm text-ink-600">{days.length} hari · durasi paket {pkg.duration_days} hari</p>
          </div>
          <ol className="space-y-3">
            {days.map((d, i) => (
              <li key={i} className="grid gap-3 rounded-[12px] bg-surface p-3 md:grid-cols-[72px_1fr_auto]">
                <span className="flex h-12 items-center font-extrabold text-primary-700">Hari {i + 1}</span>
                <div className="space-y-2">
                  <label className="sr-only" htmlFor={`it-title-${i}`}>Judul hari {i + 1}</label>
                  <input id={`it-title-${i}`} className="input" placeholder="Contoh: Jambi – Jakarta – Jeddah" value={d.title} onChange={(e) => setDay(i, { title: e.target.value })} />
                  <label className="sr-only" htmlFor={`it-act-${i}`}>Kegiatan hari {i + 1}</label>
                  <textarea id={`it-act-${i}`} className="input" rows={2} placeholder="Kegiatan, satu per baris" value={d.activities.join('\n')} onChange={(e) => setDay(i, { activities: e.target.value.split('\n') })} />
                </div>
                <div className="flex gap-1 md:flex-col">
                  <button type="button" className="min-h-10 rounded-lg px-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 disabled:opacity-40" disabled={i === 0} aria-label={`Naikkan hari ${i + 1}`}
                    onClick={() => setDays((ds) => { const c = [...ds]; [c[i - 1], c[i]] = [c[i], c[i - 1]]; return c; })}>↑</button>
                  <button type="button" className="min-h-10 rounded-lg px-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 disabled:opacity-40" disabled={i === days.length - 1} aria-label={`Turunkan hari ${i + 1}`}
                    onClick={() => setDays((ds) => { const c = [...ds]; [c[i + 1], c[i]] = [c[i], c[i + 1]]; return c; })}>↓</button>
                  <button type="button" className="min-h-10 rounded-lg px-2 text-sm font-semibold text-danger hover:bg-danger-bg" aria-label={`Hapus hari ${i + 1}`}
                    onClick={() => setDays((ds) => ds.filter((_, j) => j !== i))}>Hapus</button>
                </div>
              </li>
            ))}
          </ol>
          <button type="button" className="btn btn-outline" onClick={() => setDays((ds) => [...ds, { day: ds.length + 1, title: '', activities: [] }])}>+ Tambah hari</button>
        </div>

        <div className={section}>
          <h3 className="text-lg font-extrabold">Syarat dan ketentuan</h3>
          <Field id="document_requirements" label="Dokumen yang diperlukan"><textarea id="document_requirements" name="document_requirements" rows={3} className="input" defaultValue={pkg.document_requirements ?? ''} /></Field>
          <Field id="payment_terms" label="Ketentuan pembayaran" hint="Tenggat DP dan pelunasan, cara pembayaran."><textarea id="payment_terms" name="payment_terms" rows={3} className="input" defaultValue={pkg.payment_terms ?? ''} /></Field>
          <Field id="cancellation_terms" label="Kebijakan pembatalan" hint="Wajib sebelum terbit. Tulis persentase pengembalian per rentang hari." error={apiErr?.field('cancellation_terms')}>
            <textarea id="cancellation_terms" name="cancellation_terms" rows={3} className="input" defaultValue={pkg.cancellation_terms ?? ''} />
          </Field>
        </div>
      </fieldset>

      {msgOf(error) && <Alert tone="danger">{msgOf(error)}{error instanceof ApiClientError && error.status === 412 && <> <button type="button" className="font-bold underline" onClick={() => window.location.reload()}>Muat ulang data</button></>}</Alert>}
      {saved && <Alert tone="success">Perubahan tersimpan.</Alert>}
      {!readOnly && (
        <div className="sticky bottom-0 -mx-5 border-t border-border-subtle bg-white/95 px-5 py-3 backdrop-blur md:-mx-6 md:px-6">
          <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}Simpan perubahan</button>
        </div>
      )}
    </form>
  );
}

/** Foto paket: maks 10, JPG/PNG/WEBP ≤ 2 MB, foto pertama = sampul. */
export function PhotosPanel({ tenantId, pkg, editable }: { tenantId: string; pkg: PackageManaged; editable: boolean }) {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const base = `/tenants/${tenantId}/packages/${pkg.id}`;
  const imgs = pkg.images;

  async function upload(files: FileList | null) {
    if (!files?.length) return;
    setBusy('upload');
    setError(null);
    try {
      for (const file of Array.from(files).slice(0, 10 - imgs.length)) {
        if (file.size > 2 * 1024 * 1024) throw new Error(`${file.name}: ukuran maksimal 2 MB.`);
        const fd = new FormData();
        fd.set('purpose', 'package_image');
        fd.set('file', file);
        const f = await api<{ data: { id: string } }>('/files', { form: fd });
        await api(`${base}/images`, { json: { file_id: f.data.id } });
      }
      router.refresh();
    } catch (e) {
      setError(e instanceof ApiClientError ? (e.field('file') ?? e.message) : e instanceof Error ? e.message : errorMessage(e));
      router.refresh();
    }
    setBusy(null);
  }
  async function move(i: number, to: number) {
    const ids = imgs.map((x) => x.id);
    [ids[i], ids[to]] = [ids[to], ids[i]];
    setBusy('order');
    try { await api(`${base}/images/order`, { method: 'PUT', json: { image_ids: ids } }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
    setBusy(null);
  }
  async function remove(id: string) {
    if (!confirm('Hapus foto ini?')) return;
    setBusy(id);
    try { await api(`${base}/images/${id}`, { method: 'DELETE' }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
    setBusy(null);
  }

  return (
    <div className="space-y-3">
      {imgs.length > 0 ? (
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {imgs.map((img, i) => (
            <li key={img.id} className="overflow-hidden rounded-[12px] border border-border-subtle bg-white">
              <div className="relative h-28">
                {img.url && <Image src={img.url} alt={`Foto ${i + 1} ${pkg.name}`} fill unoptimized sizes="200px" className="object-cover" />}
                {i === 0 && <span className="absolute left-2 top-2 rounded-md bg-gold-500 px-2 py-0.5 text-xs font-bold text-ink-900">Sampul</span>}
              </div>
              {editable && (
                <div className="flex items-center justify-between gap-1 p-1">
                  <div className="flex">
                    <button type="button" className="h-10 w-10 rounded-lg font-bold text-primary-700 hover:bg-primary-50 disabled:opacity-30" disabled={i === 0 || !!busy} aria-label={`Geser foto ${i + 1} ke kiri`} onClick={() => move(i, i - 1)}>←</button>
                    <button type="button" className="h-10 w-10 rounded-lg font-bold text-primary-700 hover:bg-primary-50 disabled:opacity-30" disabled={i === imgs.length - 1 || !!busy} aria-label={`Geser foto ${i + 1} ke kanan`} onClick={() => move(i, i + 1)}>→</button>
                  </div>
                  <button type="button" className="h-10 rounded-lg px-2 text-sm font-semibold text-danger hover:bg-danger-bg" disabled={!!busy} onClick={() => remove(img.id)}>Hapus</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="rounded-[12px] border border-dashed border-border-input p-6 text-center text-ink-600">Belum ada foto. Foto pertama menjadi sampul di marketplace.</p>
      )}
      {error && <Alert tone="danger">{error}</Alert>}
      {editable && imgs.length < 10 && (
        <label className={`btn btn-outline cursor-pointer ${busy ? 'pointer-events-none opacity-70' : ''}`}>
          {busy === 'upload' && <Spinner />}+ Tambah foto
          <input type="file" accept="image/jpeg,image/png,image/webp" multiple className="sr-only" aria-label="Pilih foto paket"
            onChange={(e) => { upload(e.currentTarget.files); e.currentTarget.value = ''; }} />
        </label>
      )}
      <p className="text-sm text-ink-600">JPG, PNG, atau WEBP, maksimal 2 MB per foto, {imgs.length}/10 foto.</p>
    </div>
  );
}

const ROOMS: RoomType[] = ['quad', 'triple', 'double'];

/** Form keberangkatan: tambah baru atau ubah (PATCH + PUT harga dengan ETag berantai). */
function DepartureForm({ tenantId, packageId, dep, onDone }: { tenantId: string; packageId: string; dep?: DepartureManaged; onDone: () => void }) {
  const router = useRouter();
  const [error, setError] = useState<Err>(null);
  const [busy, setBusy] = useState(false);
  const apiErr = error instanceof ApiClientError ? error : null;
  const price = (r: RoomType) => dep?.prices.find((p) => p.room_type === r)?.price_amount;
  const locked = (dep?.seats_held ?? 0) + (dep?.seats_confirmed ?? 0) > 0;

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const prices = ROOMS.map((r) => ({ room_type: r, price_amount: numOrNull(fd.get(`price_${r}`)) })).filter((p) => p.price_amount);
    const fields = {
      departure_date: fd.get('departure_date'), return_date: fd.get('return_date'), departure_city: fd.get('departure_city'),
      quota: numOrNull(fd.get('quota')), dp_min_per_pax_amount: numOrNull(fd.get('dp_min_per_pax_amount')),
      dp_deadline_hours: numOrNull(fd.get('dp_deadline_hours')) ?? undefined, final_payment_due_on: fd.get('final_payment_due_on'),
    };
    if (!prices.length) { setError('Isi minimal satu harga kamar.'); return; }
    setBusy(true);
    setError(null);
    try {
      if (!dep) {
        await api(`/tenants/${tenantId}/packages/${packageId}/departures`, { json: { ...fields, prices } });
      } else {
        const r = await api<{ data: DepartureManaged }>(`/tenants/${tenantId}/departures/${dep.id}`, { method: 'PATCH', json: fields, headers: { 'If-Match': dep.etag } });
        const same = JSON.stringify(prices) === JSON.stringify(dep.prices);
        if (!same) await api(`/tenants/${tenantId}/departures/${dep.id}/prices`, { method: 'PUT', json: { prices }, headers: { 'If-Match': r.data.etag } });
      }
      router.refresh();
      onDone();
    } catch (err) { setError(err instanceof ApiClientError ? err : errorMessage(err)); }
    setBusy(false);
  }

  const f = (name: string) => apiErr?.field(name);
  return (
    <form onSubmit={submit} noValidate className="space-y-4 rounded-[12px] border border-primary-600 bg-primary-50/40 p-4">
      <h3 className="font-extrabold">{dep ? 'Ubah keberangkatan' : 'Keberangkatan baru'}</h3>
      {msgOf(error) && (!apiErr?.errors || apiErr.status !== 422) && <Alert tone="danger">{msgOf(error)}</Alert>}
      {locked && <Alert tone="info">Sudah ada kursi terpakai: tanggal tidak bisa diubah dan kuota tidak boleh di bawah kursi terpakai.</Alert>}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field id="d-date" label="Tanggal berangkat" error={f('departure_date')}><input id="d-date" name="departure_date" type="date" className="input" defaultValue={dep?.departure_date} required /></Field>
        <Field id="d-ret" label="Tanggal pulang" error={f('return_date')}><input id="d-ret" name="return_date" type="date" className="input" defaultValue={dep?.return_date} required /></Field>
        <Field id="d-city" label="Berangkat dari" error={f('departure_city')}><input id="d-city" name="departure_city" className="input" defaultValue={dep?.departure_city ?? 'Jambi'} list="kota" required /></Field>
        <Field id="d-quota" label="Kuota kursi" error={f('quota')}><input id="d-quota" name="quota" type="number" min={1} className="input num" defaultValue={dep?.quota ?? 45} required /></Field>
      </div>
      <datalist id="kota">{['Jambi', 'Jakarta', 'Palembang', 'Padang', 'Pekanbaru'].map((k) => <option key={k} value={k} />)}</datalist>
      <fieldset>
        <legend className="mb-1.5 font-semibold">Harga per orang (rupiah)</legend>
        <p className="mb-2 text-sm text-ink-600">Kosongkan tipe kamar yang tidak dijual. Harga ini harga resmi, sama di semua kanal.</p>
        <div className="grid gap-4 sm:grid-cols-3">
          {ROOMS.map((r) => (
            <Field key={r} id={`d-price-${r}`} label={ROOM_LABEL[r]}>
              <input id={`d-price-${r}`} name={`price_${r}`} inputMode="numeric" className="input num" defaultValue={price(r) ?? ''} placeholder="mis. 32500000" />
            </Field>
          ))}
        </div>
        {apiErr?.errors && Object.keys(apiErr.errors).some((k) => k.startsWith('prices')) && <p role="alert" className="mt-1 text-sm font-semibold text-danger">{Object.entries(apiErr.errors).find(([k]) => k.startsWith('prices'))?.[1][0]}</p>}
      </fieldset>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field id="d-dp" label="DP minimal per orang" error={f('dp_min_per_pax_amount')}><input id="d-dp" name="dp_min_per_pax_amount" inputMode="numeric" className="input num" defaultValue={dep?.dp_min_per_pax_amount ?? 5000000} required /></Field>
        <Field id="d-dph" label="Batas bayar DP (jam)" hint="Booking batal otomatis bila DP belum masuk."><input id="d-dph" name="dp_deadline_hours" type="number" min={1} max={168} className="input num" defaultValue={dep?.dp_deadline_hours ?? 48} /></Field>
        <Field id="d-final" label="Batas pelunasan" error={f('final_payment_due_on')}><input id="d-final" name="final_payment_due_on" type="date" className="input" defaultValue={dep?.final_payment_due_on} required /></Field>
      </div>
      <div className="flex flex-wrap gap-2">
        <button className="btn btn-primary" disabled={busy}>{busy && <Spinner />}{dep ? 'Simpan perubahan' : 'Tambah keberangkatan'}</button>
        <button type="button" className="btn text-primary-700 hover:bg-primary-50" onClick={onDone}>Batal</button>
      </div>
    </form>
  );
}

/** PPIU-06 Keberangkatan: tabel + aksi penjualan (FR-PPIU-05..07). */
export function DeparturesPanel({ tenantId, pkg, canManage, canPrice }: { tenantId: string; pkg: PackageManaged; canManage: boolean; canPrice: boolean }) {
  const router = useRouter();
  const [editing, setEditing] = useState<string | 'new' | null>(pkg.departures?.length ? null : (canManage ? 'new' : null));
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const deps = pkg.departures ?? [];
  const editable = ['draft', 'published'].includes(pkg.status);

  async function act(dep: DepartureManaged, action: 'close-sales' | 'open-sales' | 'cancel') {
    let json: object | undefined;
    if (action === 'cancel') {
      const reason = prompt('Alasan pembatalan keberangkatan (dikirim ke jamaah yang sudah booking):');
      if (!reason || reason.trim().length < 3) return;
      json = { reason: reason.trim() };
    }
    setBusy(dep.id + action);
    setError(null);
    try { await api(`/tenants/${tenantId}/departures/${dep.id}/${action}`, { method: 'POST', json }); router.refresh(); } catch (e) { setError(errorMessage(e)); }
    setBusy(null);
  }

  return (
    <div className="space-y-4">
      {deps.length > 0 && (
        <ul className="space-y-3">
          {deps.map((d) => editing === d.id ? (
            <li key={d.id}><DepartureForm tenantId={tenantId} packageId={pkg.id} dep={d} onDone={() => setEditing(null)} /></li>
          ) : (
            <li key={d.id} className={`rounded-[12px] border p-4 ${d.status === 'cancelled' ? 'border-border-subtle bg-surface opacity-80' : 'border-border-subtle bg-white'}`}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-lg font-extrabold">{tanggal(d.departure_date)} – {tanggal(d.return_date)}</p>
                  <p className="text-sm text-ink-600">Dari {d.departure_city} · DP min {rupiah(d.dp_min_per_pax_amount)} · lunas paling lambat {tanggal(d.final_payment_due_on)}</p>
                </div>
                <div className="flex flex-wrap gap-2"><StatusPill status={d.status === 'scheduled' ? d.sales_status : d.status} /></div>
              </div>
              <div className="mt-3 grid gap-3 sm:grid-cols-[auto_1fr]">
                <div className="rounded-[10px] bg-surface px-3 py-2">
                  <p className="text-sm text-ink-600">Kursi</p>
                  <p className="num font-bold">{d.seats_remaining} sisa <span className="font-normal text-ink-600">dari {d.quota} · {d.seats_held} ditahan · {d.seats_confirmed} terkonfirmasi</span></p>
                </div>
                <ul className="flex flex-wrap gap-2">
                  {d.prices.map((p) => (
                    <li key={p.room_type} className="rounded-[10px] border border-border-subtle px-3 py-2">
                      <p className="text-sm text-ink-600">{ROOM_LABEL[p.room_type]}</p>
                      <p className="num font-bold text-primary-800">{rupiah(p.price_amount)}</p>
                    </li>
                  ))}
                </ul>
              </div>
              {d.status === 'scheduled' && editable && (canManage || canPrice) && (
                <div className="mt-3 flex flex-wrap gap-2 border-t border-border-subtle pt-3">
                  <button type="button" className="btn btn-outline min-h-10 px-3 text-sm" onClick={() => setEditing(d.id)} disabled={!!busy}>Ubah</button>
                  {canManage && d.sales_status !== 'closed' && <button type="button" className="btn min-h-10 px-3 text-sm text-primary-700 hover:bg-primary-50" disabled={!!busy} onClick={() => act(d, 'close-sales')}>{busy === d.id + 'close-sales' && <Spinner />}Tutup penjualan</button>}
                  {canManage && d.sales_status === 'closed' && <button type="button" className="btn min-h-10 px-3 text-sm text-primary-700 hover:bg-primary-50" disabled={!!busy} onClick={() => act(d, 'open-sales')}>{busy === d.id + 'open-sales' && <Spinner />}Buka penjualan</button>}
                  {canManage && <button type="button" className="min-h-10 px-3 text-sm font-semibold text-danger hover:underline" disabled={!!busy} onClick={() => act(d, 'cancel')}>Batalkan keberangkatan</button>}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {deps.length === 0 && editing !== 'new' && <p className="rounded-[12px] border border-dashed border-border-input p-6 text-center text-ink-600">Belum ada keberangkatan.</p>}
      {error && <Alert tone="danger">{error}</Alert>}
      {canManage && editable && (editing === 'new'
        ? <DepartureForm tenantId={tenantId} packageId={pkg.id} onDone={() => setEditing(null)} />
        : <button type="button" className="btn btn-outline" onClick={() => setEditing('new')}>+ Tambah keberangkatan</button>)}
      {!editable && <p className="text-sm text-ink-600">Paket {pkg.status === 'archived' ? 'diarsipkan' : 'diturunkan'}: keberangkatan tidak bisa ditambah atau diubah. Gunakan &ldquo;Salin jadi paket baru&rdquo; untuk membuat versi baru.</p>}
    </div>
  );
}
