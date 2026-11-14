import Form from 'next/form';
import Link from 'next/link';
import { Suspense } from 'react';
import type { Metadata } from 'next';
import { getFacets, getPackages, type Facets } from '@/lib/api';
import { bulanTahun, budgetSteps, rupiah } from '@/lib/format';
import { PackageRow } from '@/components/ui';
import { IconFilter, IconSearch, IconUsers, IconX } from '@/components/icons';

export const metadata: Metadata = { title: 'Cari paket umrah' };

type SP = Record<string, string | string[] | undefined>;
type Params = { q?: string; month?: string; airline?: string; departure_city?: string; price_max?: string; elderly?: string; sort: string; cursor?: string };
const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v)?.trim() || undefined;

const SORTS = [
  { value: 'departure_date', label: 'Berangkat terdekat' },
  { value: 'price', label: 'Harga termurah' },
  { value: '-price', label: 'Harga tertinggi' },
  { value: '-rating', label: 'Rating tertinggi' },
  { value: '-published_at', label: 'Terbaru' },
] as const;

/** MKT-01 Hasil pencarian paket (FR-MKT-01..03): filter kiri, tab urutan, kartu horizontal. */
export default function SearchPage({ searchParams }: PageProps<'/paket'>) {
  return (
    <>
      <section className="bg-hero text-white">
        <div className="mx-auto max-w-6xl px-4 py-8">
          <p className="text-sm font-semibold uppercase tracking-wider text-gold-500">Paket umrah</p>
          <h1 className="mt-1 text-[28px] font-extrabold leading-9 md:text-4xl md:leading-[44px]">Temukan paket yang pas untuk Anda</h1>
          <p className="mt-1 text-white/85">Semua paket dari PPIU terverifikasi, dengan harga resmi per orang.</p>
        </div>
      </section>
      <div className="mx-auto max-w-6xl px-4 py-6">
        <Suspense fallback={<ResultsSkeleton />}>
          <Results searchParams={searchParams} />
        </Suspense>
      </div>
    </>
  );
}

async function Results({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const params: Params = {
    q: one(sp.q), month: one(sp.month), airline: one(sp.airline), departure_city: one(sp.departure_city),
    price_max: one(sp.price_max), elderly: one(sp.elderly) === '1' ? '1' : undefined, sort: SORTS.some((s) => s.value === one(sp.sort)) ? one(sp.sort)! : 'departure_date', cursor: one(sp.cursor),
  };
  const [facets, list] = await Promise.all([getFacets(), getPackages({ ...params })]);

  const filters = { q: params.q, month: params.month, airline: params.airline, departure_city: params.departure_city, price_max: params.price_max, elderly: params.elderly };
  const href = (over: Partial<Params>) => {
    const merged: Record<string, string | undefined> = { ...filters, sort: params.sort === 'departure_date' ? undefined : params.sort, ...over };
    const query = Object.fromEntries(Object.entries(merged).filter(([, v]) => v)) as Record<string, string>;
    return { pathname: '/paket', query };
  };
  const chips = [
    params.q && { key: 'q', label: `“${params.q}”` },
    params.month && { key: 'month', label: bulanTahun(params.month) },
    params.departure_city && { key: 'departure_city', label: `Dari ${params.departure_city}` },
    params.airline && { key: 'airline', label: params.airline },
    params.price_max && { key: 'price_max', label: `≤ ${rupiah(Number(params.price_max))}` },
    params.elderly && { key: 'elderly', label: 'Ramah lansia' },
  ].filter(Boolean) as { key: keyof Params; label: string }[];

  return (
    <div className="grid gap-6 lg:grid-cols-[280px_1fr]">
      <aside aria-label="Filter paket">
        <details className="card group lg:hidden">
          <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between px-4 font-bold">
            <span className="inline-flex items-center gap-2"><IconFilter />Filter{chips.length ? ` (${chips.length})` : ''}</span>
            <span className="text-sm text-primary-700 group-open:hidden">Buka</span>
            <span className="hidden text-sm text-primary-700 group-open:inline">Tutup</span>
          </summary>
          <div className="border-t border-border-subtle p-4"><FilterForm idp="m" params={params} facets={facets} /></div>
        </details>
        <div className="card hidden p-4 lg:sticky lg:top-20 lg:block">
          <h2 className="mb-3 flex items-center gap-2 text-lg font-extrabold"><IconFilter />Filter</h2>
          <FilterForm idp="d" params={params} facets={facets} />
        </div>
      </aside>

      <section aria-labelledby="hasil" className="min-w-0">
        <nav aria-label="Urutkan" className="card no-scrollbar flex overflow-x-auto p-1">
          {SORTS.map((s) => {
            const active = params.sort === s.value;
            return (
              <Link key={s.value} href={href({ sort: s.value === 'departure_date' ? undefined : s.value, cursor: undefined })}
                aria-current={active ? 'true' : undefined}
                className={`flex min-h-12 flex-1 shrink-0 items-center justify-center whitespace-nowrap rounded-lg px-4 text-sm font-bold transition ${active ? 'bg-primary-700 text-white' : 'text-ink-600 hover:bg-primary-50 hover:text-primary-700'}`}>
                {s.label}
              </Link>
            );
          })}
        </nav>

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <h2 id="hasil" className="mr-2 font-bold">
            {list.data.length} paket {chips.length ? 'cocok' : 'tersedia'}{params.cursor ? ' (halaman berikutnya)' : ''}
          </h2>
          {chips.map((c) => (
            <Link key={c.key} href={href({ [c.key]: undefined, cursor: undefined })} className="chip border-primary-700 bg-primary-50 text-primary-800 hover:bg-primary-100">
              {c.label}<IconX className="h-4 w-4" /><span className="sr-only">hapus filter</span>
            </Link>
          ))}
          {chips.length > 1 && <Link href="/paket" className="inline-flex min-h-10 items-center px-2 text-sm font-bold text-primary-700 underline">Hapus semua</Link>}
        </div>

        {list.data.length === 0 ? (
          <div className="card mt-4 p-10 text-center">
            <IconSearch className="mx-auto h-10 w-10 text-gold-700" />
            <p className="mt-3 text-lg font-bold">Belum ada paket yang cocok</p>
            <p className="mt-1 text-ink-600">Coba ganti bulan, kota keberangkatan, atau naikkan budget.</p>
            <Link href="/paket" className="btn btn-outline mt-5">Hapus semua filter</Link>
          </div>
        ) : (
          <div className="mt-4 grid gap-4">
            {list.data.map((p) => <PackageRow key={p.id} p={p} />)}
          </div>
        )}

        {list.meta.next_cursor && (
          <div className="mt-6 text-center">
            <Link className="btn btn-outline" href={href({ cursor: list.meta.next_cursor })}>Muat paket berikutnya</Link>
          </div>
        )}
      </section>
    </div>
  );
}

function FilterForm({ idp, params, facets }: { idp: string; params: Params; facets: Facets }) {
  return (
    <Form action="/paket" className="space-y-4">
      {params.sort !== 'departure_date' && <input type="hidden" name="sort" value={params.sort} />}
      <Field label="Cari" htmlFor={`${idp}-q`}>
        <input id={`${idp}-q`} name="q" defaultValue={params.q} placeholder="Nama paket, PPIU, hotel" className="input" />
      </Field>
      <Field label="Bulan keberangkatan" htmlFor={`${idp}-month`}>
        <select id={`${idp}-month`} name="month" defaultValue={params.month ?? ''} className="input">
          <option value="">Semua bulan</option>
          {facets.months.map((m) => <option key={m} value={m}>{bulanTahun(m)}</option>)}
        </select>
      </Field>
      <Field label="Berangkat dari" htmlFor={`${idp}-city`}>
        <select id={`${idp}-city`} name="departure_city" defaultValue={params.departure_city ?? ''} className="input">
          <option value="">Semua kota</option>
          {facets.departure_cities.map((c) => <option key={c}>{c}</option>)}
        </select>
      </Field>
      <Field label="Budget per orang" htmlFor={`${idp}-price`}>
        <select id={`${idp}-price`} name="price_max" defaultValue={params.price_max ?? ''} className="input">
          <option value="">Semua harga</option>
          {budgetSteps(facets.price_min_amount).map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
        </select>
      </Field>
      <Field label="Maskapai" htmlFor={`${idp}-airline`}>
        <select id={`${idp}-airline`} name="airline" defaultValue={params.airline ?? ''} className="input">
          <option value="">Semua maskapai</option>
          {facets.airlines.map((a) => <option key={a}>{a}</option>)}
        </select>
      </Field>
      <label htmlFor={`${idp}-elderly`} className="flex min-h-12 cursor-pointer items-start gap-3 rounded-[10px] border border-border-input bg-white p-3">
        <input id={`${idp}-elderly`} type="checkbox" name="elderly" value="1" defaultChecked={params.elderly === '1'} className="mt-0.5 h-5 w-5 accent-[var(--color-primary-700)]" />
        <span><span className="flex items-center gap-1.5 font-semibold"><IconUsers className="h-4 w-4 text-primary-700" />Ramah lansia</span><span className="text-sm text-ink-600">Hotel Makkah & Madinah paling jauh 300 m dari masjid</span></span>
      </label>
      <button type="submit" className="btn btn-gold w-full">Terapkan filter</button>
    </Form>
  );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: React.ReactNode }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="mb-1 block text-sm font-bold">{label}</label>
      {children}
    </div>
  );
}

function ResultsSkeleton() {
  return (
    <div className="grid gap-6 lg:grid-cols-[280px_1fr]" aria-busy="true" aria-label="Memuat paket">
      <div className="card hidden h-96 animate-pulse lg:block" />
      <div className="grid gap-4">{Array.from({ length: 4 }, (_, i) => <div key={i} className="card h-44 animate-pulse" />)}</div>
    </div>
  );
}
