import { PageBanner } from '@/components/ui';
import { IconHeading } from '@/components/stat-card';
import { IconEye, IconFile, IconShieldCheck } from '@/components/icons';

export const metadata = { title: 'Kebijakan Privasi' };

export default function PrivacyPage() {
  return (
    <>
      <PageBanner eyebrow="Versi 2026-11-v1 · draf" title="Kebijakan Privasi" />
      <article className="mx-auto max-w-3xl space-y-4 px-4 py-8 leading-7">
        <p className="rounded-[10px] border border-warning bg-warning-bg px-4 py-3 font-semibold">Draf untuk development. Teks final menunggu tinjauan konsultan hukum (UU PDP No. 27/2022).</p>
        <IconHeading icon={IconFile} className="text-xl font-extrabold">Data yang kami kumpulkan</IconHeading>
        <p>Nama, email atau nomor HP, dan data yang Anda isi saat memesan atau mendaftarkan PPIU. Data identitas jamaah (NIK, paspor) disimpan terenkripsi dan hanya dapat dibuka oleh PPIU penyelenggara.</p>
        <IconHeading icon={IconEye} className="text-xl font-extrabold">Penggunaan</IconHeading>
        <p>Data dipakai untuk memproses pemesanan, verifikasi PPIU, dan pemberitahuan terkait akun Anda. Kami tidak menjual data pribadi.</p>
        <IconHeading icon={IconShieldCheck} className="text-xl font-extrabold">Hak Anda</IconHeading>
        <p>Anda dapat meminta salinan atau penghapusan data melalui menu Akun.</p>
      </article>
    </>
  );
}
