import { PageBanner } from '@/components/ui';

export const metadata = { title: 'Kebijakan Privasi' };

export default function PrivacyPage() {
  return (
    <>
      <PageBanner eyebrow="Versi 2026-11-v1 · draf" title="Kebijakan Privasi" />
      <article className="mx-auto max-w-3xl space-y-4 px-4 py-8 leading-7">
        <p className="rounded-[10px] border border-warning bg-warning-bg px-4 py-3 font-semibold">Draf untuk development. Teks final menunggu tinjauan konsultan hukum (UU PDP No. 27/2022).</p>
        <h2 className="text-xl font-extrabold">Data yang kami kumpulkan</h2>
        <p>Nama, email atau nomor HP, dan data yang Anda isi saat memesan atau mendaftarkan PPIU. Data identitas jamaah (NIK, paspor) disimpan terenkripsi dan hanya dapat dibuka oleh PPIU penyelenggara.</p>
        <h2 className="text-xl font-extrabold">Penggunaan</h2>
        <p>Data dipakai untuk memproses pemesanan, verifikasi PPIU, dan pemberitahuan terkait akun Anda. Kami tidak menjual data pribadi.</p>
        <h2 className="text-xl font-extrabold">Hak Anda</h2>
        <p>Anda dapat meminta salinan atau penghapusan data melalui menu Akun.</p>
      </article>
    </>
  );
}
