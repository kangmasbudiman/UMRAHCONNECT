import Form from 'next/form';
import Link from 'next/link';
import { Suspense } from 'react';
import { getFacets, getTenants, lokasiLabel } from '@/lib/api';
import { PageBanner, TenantCard } from '@/components/ui';
import { LokasiSelect } from '@/components/lokasi-select';
import { IconMapPin, IconX } from '@/components/icons';
import { NEAR_DEFAULT_RADIUS, NEAR_RADII, parseNear } from '@/components/map/map-config';
import { PpiuMap, type PpiuPin } from '@/components/map/ppiu-map';
import { NearMeButton } from '@/components/map/near-me-button';

export const metadata = { title: 'Direktori PPIU' };

const one = (v: string | string[] | undefined) => (Array.isArray(v) ? v[0] : v);

/**
 * MKT-03 Direktori PPIU terverifikasi (FR-MKT-06): daftar + peta.
 * Saring per provinsi / kab-kota kantor (`?lokasi=`), atau urutkan dari yang terdekat (`?dekat=lat,lng&jarak=km`).
 */
export default function TenantsPage({ searchParams }: PageProps<'/ppiu'>) {
  return (
    <>
      <PageBanner eyebrow="Direktori" title="PPIU terverifikasi">
        Setiap PPIU di bawah sudah diperiksa dokumen badan usaha dan izin PPIU-nya oleh tim UMRAH CONNECT.
      </PageBanner>
      <div className="mx-auto max-w-6xl px-4 py-8">
        <Suspense fallback={<div className="card h-40 animate-pulse" />}>
          <List searchParams={searchParams} />
        </Suspense>
      </div>
    </>
  );
}

async function List({ searchParams }: { searchParams: PageProps<'/ppiu'>['searchParams'] }) {
  const sp = await searchParams;
  const near = parseNear(one(sp.dekat));
  const jarak = Number(one(sp.jarak));
  const radius = (NEAR_RADII as readonly number[]).includes(jarak) ? jarak : NEAR_DEFAULT_RADIUS;
  const raw = one(sp.lokasi);
  // "Terdekat" dan filter wilayah tidak digabung: hasil terdekat bisa melewati batas provinsi.
  const lokasi = !near && raw && /^\d{2}(\.\d{2})?$/.test(raw) ? raw : undefined;
  const nearParam = near ? `${near.lat},${near.lng}` : undefined;
  const [{ data }, facets] = await Promise.all([getTenants(lokasi, nearParam, radius), getFacets()]);
  const label = lokasiLabel(lokasi, facets.ppiu_regions);
  const pins: PpiuPin[] = data.flatMap((t) => (t.latitude != null && t.longitude != null
    ? [{ slug: t.slug, name: t.brand_name, city: t.city, lat: t.latitude, lng: t.longitude, distance_km: t.distance_km }]
    : []));
  const unpinned = data.length - pins.length;
  const radiusHref = (r: number) => `/ppiu?dekat=${nearParam}&jarak=${r}`;
  const wider = NEAR_RADII.filter((r) => r > radius);

  return (
    <>
      <div className="card mb-6 flex flex-wrap items-end gap-3 p-4">
        <Form action="/ppiu" className="flex min-w-0 flex-[1_1_28rem] flex-wrap items-end gap-3">
          <div className="min-w-64 flex-1">
            <label htmlFor="ppiu-lokasi" className="mb-1.5 flex items-center gap-1.5 font-semibold"><IconMapPin className="h-4 w-4 text-primary-700" />Lokasi kantor PPIU</label>
            <LokasiSelect id="ppiu-lokasi" value={lokasi} regions={facets.ppiu_regions} />
          </div>
          <button type="submit" className="btn btn-primary">Tampilkan</button>
        </Form>
        <span className="hidden self-center pb-1 text-sm font-semibold text-ink-600 sm:block">atau</span>
        <NearMeButton radius={near ? radius : undefined} />
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <p className="font-bold" aria-live="polite">
          {near
            ? `${data.length} PPIU terverifikasi dalam ${radius} km dari lokasi Anda`
            : `${data.length} PPIU terverifikasi${label ? ` di ${label}` : ''}`}
        </p>
        {(lokasi || near) && (
          <Link href="/ppiu" className="inline-flex items-center gap-1 rounded-full bg-primary-50 px-3 py-1 text-sm font-semibold text-primary-700 hover:bg-primary-100">
            <IconX className="h-4 w-4" />{near ? 'Hapus lokasi saya' : 'Hapus filter'}
          </Link>
        )}
      </div>
      {near && (
        <nav aria-label="Jarak pencarian" className="mb-4 flex flex-wrap items-center gap-2 text-sm">
          <span className="font-semibold text-ink-600">Jarak:</span>
          {NEAR_RADII.map((r) => (
            <Link key={r} href={radiusHref(r)} aria-current={r === radius ? 'true' : undefined}
              className={`inline-flex min-h-9 items-center rounded-full border px-3 font-semibold ${r === radius ? 'border-primary-700 bg-primary-700 text-white' : 'border-border-input text-ink-900 hover:bg-primary-50'}`}>
              {r} km
            </Link>
          ))}
        </nav>
      )}

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,42%)]">
        <div className="order-2 lg:order-1">
          {data.length === 0 ? (
            <div className="card p-8 text-center text-ink-600">
              {near ? (
                <>
                  <p>Belum ada PPIU terverifikasi dalam {radius} km dari lokasi Anda.</p>
                  {wider.length > 0 && (
                    <p className="mt-3">
                      Perluas ke{' '}
                      {wider.map((r, i) => (
                        <span key={r}>{i > 0 && ', '}<Link href={radiusHref(r)} className="font-bold text-primary-700 underline">{r} km</Link></span>
                      ))}
                      , atau <Link href="/ppiu" className="font-bold text-primary-700 underline">lihat seluruh Indonesia</Link>.
                    </p>
                  )}
                </>
              ) : (
                <p>Belum ada PPIU terverifikasi di daerah ini. Coba provinsi lain atau lihat seluruh Indonesia.</p>
              )}
            </div>
          ) : (
            <ul className="grid gap-4 sm:grid-cols-2">
              {data.map((t) => <li key={t.id}><TenantCard t={t} /></li>)}
            </ul>
          )}
        </div>
        <aside className="order-1 lg:order-2" aria-label="Peta PPIU">
          <div className="lg:sticky lg:top-24">
            <PpiuMap pins={pins} me={near} />
            {unpinned > 0 && <p className="mt-2 text-sm text-ink-600">{unpinned} PPIU belum menandai lokasi kantor di peta.</p>}
          </div>
        </aside>
      </div>
    </>
  );
}
