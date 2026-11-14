import Link from 'next/link';
import { LegalPage } from '@/components/legal';

export const metadata = { title: 'Kebijakan Privasi' };

/** Draf Kebijakan Privasi berbasis UU PDP No. 27/2022. Versi harus sama dengan LEGAL_PRIVACY_VERSION di API. */
export default function PrivacyPage() {
  return (
    <LegalPage title="Kebijakan Privasi" version="2026-11-v1"
      intro={<p>Kebijakan ini menjelaskan data pribadi apa yang dikumpulkan UMRAH CONNECT, untuk apa, dengan siapa dibagikan, berapa lama disimpan, dan bagaimana Anda menggunakan hak Anda. UMRAH CONNECT bertindak sebagai <strong>pengendali data</strong> untuk data akun, dan bersama PPIU penyelenggara untuk data pemesanan dan jamaah.</p>}
      sections={[
        { id: 'data', title: 'Data yang kami kumpulkan', body: (
          <ul>
            <li><strong>Akun:</strong> nama, email dan/atau nomor HP, kata sandi (disimpan dalam bentuk hash), pengaturan 2FA.</li>
            <li><strong>Data jamaah:</strong> nama lengkap, NIK, tempat & tanggal lahir, jenis kelamin, nomor dan masa berlaku paspor, kontak darurat, dokumen (paspor, pas foto, kartu vaksin). NIK dan nomor paspor disimpan terenkripsi.</li>
            <li><strong>Transaksi:</strong> booking, invoice, bukti transfer, kwitansi, refund.</li>
            <li><strong>Agen:</strong> NIK (terenkripsi), domisili, rekening pencairan komisi, catatan prospek yang Anda buat.</li>
            <li><strong>Teknis & keamanan:</strong> alamat IP, jenis perangkat/peramban, waktu masuk, dan catatan audit atas tindakan sensitif.</li>
            <li><strong>Persetujuan:</strong> versi syarat & kebijakan yang Anda setujui beserta waktunya.</li>
          </ul>) },
        { id: 'tujuan', title: 'Tujuan dan dasar pemrosesan', body: (
          <ul>
            <li>Membuat dan mengamankan akun (pelaksanaan perjanjian).</li>
            <li>Memproses pemesanan umrah, pembayaran, dan pembuatan manifes oleh PPIU (pelaksanaan perjanjian dan kewajiban hukum PPIU).</li>
            <li>Mengirim notifikasi penting: tagihan, status booking, Lacak Umrah, pengingat tabungan (pelaksanaan perjanjian; pengingat tabungan atas persetujuan Anda dan bisa dimatikan).</li>
            <li>Mencegah penipuan dan penyalahgunaan, termasuk pemberitahuan login dari perangkat baru (kepentingan yang sah).</li>
            <li>Kami <strong>tidak menjual</strong> data pribadi dan tidak memakainya untuk iklan pihak ketiga.</li>
          </ul>) },
        { id: 'bagi', title: 'Dengan siapa data dibagikan', body: (
          <ul>
            <li><strong>PPIU penyelenggara</strong> paket yang Anda pesan: data pemesan dan jamaah untuk keperluan visa, tiket, hotel, dan manifes.</li>
            <li><strong>Agen</strong> yang membantu Anda: hanya nama jamaah, tipe kamar, dan status pembayaran — <em>tidak</em> termasuk NIK, paspor, dan tanggal lahir.</li>
            <li><strong>Penyedia layanan</strong> yang memproses data atas perintah kami: hosting, pengiriman email, gateway WhatsApp, pemindai virus file, dan perlindungan bot.</li>
            <li><strong>Otoritas</strong> bila diwajibkan peraturan perundang-undangan.</li>
          </ul>) },
        { id: 'simpan', title: 'Lama penyimpanan', body: (
          <ul>
            <li>Data akun: selama akun aktif. Saat akun dihapus, identitas akun dihapus atau dianonimkan.</li>
            <li>Data pemesanan, invoice, kwitansi, dan manifes jamaah: disimpan PPIU sesuai kewajiban pencatatan keuangan dan penyelenggaraan umrah (rencana: 10 tahun — menunggu konfirmasi hukum), tidak lagi terhubung ke akun yang dihapus.</li>
            <li>Catatan audit keamanan: rencana 5 tahun.</li>
            <li>Backup database: 30 hari, lalu dihapus otomatis.</li>
          </ul>) },
        { id: 'aman', title: 'Keamanan data', body: (
          <ul>
            <li>Koneksi terenkripsi (HTTPS), NIK dan paspor terenkripsi di database, kata sandi di-hash.</li>
            <li>Akses berbasis peran; staf PPIU hanya melihat data PPIU-nya; setiap pembukaan NIK lengkap tercatat.</li>
            <li>Verifikasi dua langkah wajib untuk Owner, Finance, dan staf platform.</li>
            <li>File yang diunggah diperiksa jenis isinya dan dipindai virus.</li>
            <li>Bila terjadi kegagalan pelindungan data, kami memberi tahu Anda dan otoritas paling lambat 3 × 24 jam sesuai UU PDP.</li>
          </ul>) },
        { id: 'hak', title: 'Hak Anda', body: (
          <>
            <ul>
              <li><strong>Akses & salinan:</strong> unduh data pribadi Anda (format JSON) dari menu Akun → Privasi.</li>
              <li><strong>Perbaikan:</strong> ubah nama di Akun; data jamaah dapat diperbarui sebelum diproses PPIU, atau melalui PPIU.</li>
              <li><strong>Penghapusan:</strong> hapus akun dari menu Akun → Privasi. Pengecualian: data yang wajib disimpan PPIU menurut hukum.</li>
              <li><strong>Menarik persetujuan:</strong> matikan pengingat tabungan kapan saja; menarik persetujuan pemrosesan inti berarti layanan tidak dapat diberikan.</li>
              <li><strong>Keberatan & pengaduan:</strong> hubungi kami; bila tidak puas, Anda dapat mengadu ke lembaga pelindungan data pribadi.</li>
            </ul>
            <p>Kelola semuanya di <Link href="/akun#privasi" className="font-semibold text-primary-700 underline">halaman Akun</Link>.</p>
          </>) },
        { id: 'kontak', title: 'Kontak', body: <p>Pertanyaan atau permintaan terkait data pribadi: <strong>privasi@umrahconnect.id</strong> (alamat contoh — diganti sebelum peluncuran). Kami menjawab paling lambat 3 × 24 jam hari kerja.</p> },
        { id: 'ubah', title: 'Perubahan kebijakan', body: <p>Bila kebijakan berubah secara material, kami memberi tahu lewat email/notifikasi dan meminta persetujuan ulang pada versi baru sebelum Anda dapat melanjutkan transaksi.</p> },
      ]}
    />
  );
}
