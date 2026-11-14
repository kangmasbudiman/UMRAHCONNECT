import { PageBanner } from '@/components/ui';

export const metadata = { title: 'Syarat & Ketentuan' };

/** Draf versi 2026-11-v1 — teks final menunggu tinjauan legal (BRD: keputusan legal). */
export default function TermsPage() {
  return (
    <>
      <PageBanner eyebrow="Versi 2026-11-v1 · draf" title="Syarat & Ketentuan" />
      <article className="mx-auto max-w-3xl space-y-4 px-4 py-8 leading-7">
        <p className="rounded-[10px] border border-warning bg-warning-bg px-4 py-3 font-semibold">Draf untuk development. Teks final menunggu tinjauan konsultan hukum.</p>
        <h2 className="text-xl font-extrabold">1. Peran UMRAH CONNECT</h2>
        <p>UMRAH CONNECT adalah platform teknologi dan pemasaran yang mempertemukan jamaah, agen, dan Penyelenggara Perjalanan Ibadah Umrah (PPIU) berizin. Penyelenggara perjalanan adalah PPIU yang tercantum pada setiap paket.</p>
        <h2 className="text-xl font-extrabold">2. Pembayaran</h2>
        <p>Seluruh pembayaran jamaah ditransfer langsung ke rekening resmi PPIU yang telah diverifikasi. UMRAH CONNECT tidak menerima dana jamaah.</p>
        <h2 className="text-xl font-extrabold">3. Akun</h2>
        <p>Anda bertanggung jawab menjaga kerahasiaan kata sandi dan kode verifikasi. Jangan berikan kode OTP kepada siapa pun, termasuk petugas UMRAH CONNECT.</p>
      </article>
    </>
  );
}
