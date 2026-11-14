import Link from 'next/link';
import { LegalPage } from '@/components/legal';

export const metadata = { title: 'Syarat & Ketentuan' };

/** Draf Syarat & Ketentuan. Versi harus sama dengan LEGAL_TERMS_VERSION di API. */
export default function TermsPage() {
  return (
    <LegalPage title="Syarat & Ketentuan" version="2026-11-v1"
      intro={<p>Dengan membuat akun atau memesan lewat UMRAH CONNECT, Anda menyetujui syarat ini. Baca juga <Link href="/privasi" className="font-semibold text-primary-700 underline">Kebijakan Privasi</Link>.</p>}
      sections={[
        { id: 'peran', title: 'Peran UMRAH CONNECT', body: <p>UMRAH CONNECT adalah platform teknologi dan pemasaran yang mempertemukan jamaah, agen, dan Penyelenggara Perjalanan Ibadah Umrah (PPIU) berizin. <strong>Penyelenggara perjalanan adalah PPIU</strong> yang tercantum di setiap paket; PPIU bertanggung jawab atas pelaksanaan perjalanan, visa, tiket, hotel, dan layanan di tanah suci.</p> },
        { id: 'ppiu', title: 'Verifikasi PPIU', body: <p>Setiap PPIU diperiksa izin, legalitas, dan rekening resminya sebelum dapat menjual paket. Verifikasi bukan jaminan atas kinerja PPIU; laporkan masalah lewat WhatsApp/email kami agar dapat ditindak (termasuk penangguhan PPIU atau penurunan paket).</p> },
        { id: 'bayar', title: 'Harga dan pembayaran', body: (
          <ul>
            <li>Harga yang tampil adalah harga resmi PPIU per orang dan sama di semua kanal, termasuk melalui agen.</li>
            <li>Seluruh pembayaran ditransfer langsung ke <strong>rekening resmi PPIU</strong> yang tercantum di invoice. UMRAH CONNECT tidak menerima atau menahan dana jamaah. Jangan transfer ke rekening pribadi siapa pun.</li>
            <li>Kursi ditahan sampai tenggat DP. Bila DP tidak dibayar tepat waktu, booking batal otomatis dan kursi dilepas.</li>
            <li>Kwitansi resmi terbit setelah PPIU memverifikasi pembayaran.</li>
          </ul>) },
        { id: 'batal', title: 'Pembatalan dan refund', body: <p>Pembatalan dan besaran refund mengikuti <strong>kebijakan pembatalan paket</strong> yang tercantum di halaman paket dan diputuskan PPIU. Refund ditransfer PPIU ke rekening Anda atau dialihkan ke booking lain atas permintaan Anda; setiap refund tercatat di booking.</p> },
        { id: 'agen', title: 'Agen', body: <p>Agen adalah mitra pemasaran PPIU. Komisi agen dibayar PPIU dan tidak menambah harga Anda. Agen tidak dapat melihat NIK, paspor, atau tanggal lahir jamaah.</p> },
        { id: 'ulasan', title: 'Ulasan', body: <p>Ulasan hanya dapat ditulis oleh pemesan yang sudah berangkat. Ulasan harus jujur dan sopan; ulasan yang memuat data pribadi, kata kasar, atau promosi dapat disembunyikan.</p> },
        { id: 'akun', title: 'Akun dan keamanan', body: (
          <ul>
            <li>Anda bertanggung jawab menjaga kata sandi dan kode verifikasi. Jangan berikan kode OTP kepada siapa pun, termasuk petugas UMRAH CONNECT.</li>
            <li>Kami dapat menonaktifkan akun yang dipakai untuk penipuan atau penyalahgunaan.</li>
            <li>Anda dapat menghapus akun kapan saja dari menu Akun, dengan pengecualian sebagaimana diatur Kebijakan Privasi.</li>
          </ul>) },
        { id: 'tabungan', title: 'Rencana Tabungan Umrah', body: <p>Fitur ini hanya mencatat rencana dan setoran yang Anda masukkan sendiri. UMRAH CONNECT tidak menyimpan, mengelola, atau menjamin dana tabungan Anda.</p> },
        { id: 'tanggung', title: 'Batas tanggung jawab', body: <p>UMRAH CONNECT bertanggung jawab atas ketersediaan dan keamanan platform. Tanggung jawab atas penyelenggaraan perjalanan berada pada PPIU sesuai peraturan penyelenggaraan ibadah umrah.</p> },
        { id: 'hukum', title: 'Hukum yang berlaku', body: <p>Syarat ini tunduk pada hukum Republik Indonesia. Sengketa diselesaikan secara musyawarah terlebih dahulu.</p> },
      ]}
    />
  );
}
