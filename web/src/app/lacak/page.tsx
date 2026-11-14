import Link from 'next/link';
import { connection } from 'next/server';
import { Suspense } from 'react';
import { Alert } from '@/components/form';
import { IconRoute, IconSearch, IconShieldCheck } from '@/components/icons';
import { JourneyTimeline } from '@/components/journey';
import { PageBanner, WhatsAppButton } from '@/components/ui';
import { trackBooking } from '@/lib/api';
import { tanggal, waLink } from '@/lib/format';

export const metadata = { title: 'Lacak Umrah', description: 'Lacak persiapan keberangkatan umrah Anda: DP, dokumen, visa, tiket, manasik, sampai berangkat.' };

async function Result({ searchParams }: PageProps<'/lacak'>) {
  await connection();
  const sp = await searchParams;
  const code = typeof sp.kode === 'string' ? sp.kode.trim().toUpperCase().slice(0, 20) : '';
  const phone = typeof sp.hp === 'string' ? sp.hp.replace(/\D/g, '').slice(-4) : '';
  return (
    <>
      <form className="card grid gap-3 p-5 sm:grid-cols-[1fr_180px_auto] sm:items-end" role="search" aria-label="Lacak booking">
        <div>
          <label htmlFor="kode" className="mb-1.5 block font-semibold">Kode booking</label>
          <input id="kode" name="kode" defaultValue={code} className="input uppercase" placeholder="UC-2611-0001" autoComplete="off" required />
        </div>
        <div>
          <label htmlFor="hp" className="mb-1.5 block font-semibold">4 digit akhir HP pemesan</label>
          <input id="hp" name="hp" defaultValue={phone} inputMode="numeric" maxLength={4} pattern="\d{4}" className="input num" placeholder="1234" required />
        </div>
        <button className="btn btn-primary"><IconSearch className="h-5 w-5" />Lacak</button>
      </form>
      {code && phone.length === 4 && <Tracked code={code} phone={phone} />}
      {code && phone.length !== 4 && <Alert tone="warning">Masukkan 4 digit terakhir nomor HP pemesan.</Alert>}
    </>
  );
}

async function Tracked({ code, phone }: { code: string; phone: string }) {
  const t = await trackBooking(code, phone);
  if (!t) return <Alert tone="danger" title="Booking tidak ditemukan">Periksa kode booking (ada di invoice/pesan WhatsApp dari agen atau PPIU) dan 4 digit terakhir nomor HP pemesan.</Alert>;
  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
      <JourneyTimeline journey={t.journey} title={`Lacak Umrah · ${t.code}`} />
      <aside className="space-y-4">
        <div className="card p-5">
          <p className="text-lg font-extrabold">{t.package.name}</p>
          <p className="text-ink-600">{t.tenant.brand_name} · izin {t.tenant.ppiu_license_no}</p>
          <dl className="mt-3 space-y-1 text-sm">
            <div className="flex justify-between gap-2"><dt className="text-ink-600">Berangkat</dt><dd className="font-semibold">{tanggal(t.departure.departure_date)} · {t.departure.departure_city}</dd></div>
            <div className="flex justify-between gap-2"><dt className="text-ink-600">Pulang</dt><dd className="font-semibold">{tanggal(t.departure.return_date)}</dd></div>
            <div className="flex justify-between gap-2"><dt className="text-ink-600">Jamaah</dt><dd className="font-semibold">{t.participant_count} orang</dd></div>
          </dl>
          <WhatsAppButton className="mt-4 w-full" href={waLink(t.tenant.whatsapp, `Assalamualaikum, saya ingin bertanya tentang booking ${t.code}.`)} label={`Tanya ${t.tenant.brand_name}`} />
        </div>
        <p className="text-sm text-ink-600">Punya akun? <Link href="/akun/booking" className="font-semibold text-primary-700 underline">Masuk</Link> untuk melihat tagihan, kwitansi, dan mengunggah dokumen.</p>
      </aside>
    </div>
  );
}

/** Lacak Umrah publik: kode booking + 4 digit akhir HP, tanpa data pribadi (untuk jamaah walk-in lewat agen/PPIU). */
export default function TrackPage(props: PageProps<'/lacak'>) {
  return (
    <>
      <PageBanner eyebrow="Lacak Umrah" title="Pantau persiapan keberangkatan Anda">
        DP, dokumen, visa, tiket, hotel, manasik, sampai berangkat. Diperbarui langsung oleh PPIU, seperti melacak kiriman paket.
      </PageBanner>
      <div className="mx-auto max-w-6xl space-y-5 px-4 py-6">
        <Suspense fallback={<div className="card h-32 animate-pulse" />}><Result {...props} /></Suspense>
        <ul className="grid gap-3 text-sm sm:grid-cols-3">
          <li className="card flex gap-3 p-4"><IconRoute className="h-6 w-6 text-primary-700" />Setiap tahap diperbarui PPIU dan Anda mendapat notifikasi.</li>
          <li className="card flex gap-3 p-4"><IconShieldCheck className="h-6 w-6 text-primary-700" />Halaman ini tidak menampilkan nama, NIK, atau nomor paspor.</li>
          <li className="card flex gap-3 p-4"><IconSearch className="h-6 w-6 text-primary-700" />Kode booking ada di invoice dan pesan konfirmasi.</li>
        </ul>
      </div>
    </>
  );
}
