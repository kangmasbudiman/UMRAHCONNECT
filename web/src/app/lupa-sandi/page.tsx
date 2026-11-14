import Link from 'next/link';
import { AuthShell } from '@/components/form';
import { ResetForm } from './reset-form';

export const metadata = { title: 'Lupa kata sandi' };

export default function ForgotPage() {
  return (
    <AuthShell title="Atur ulang kata sandi" sub="Kami kirim kode verifikasi ke email atau WhatsApp akun Anda."
      footer={<Link href="/masuk" className="font-bold text-primary-700 hover:underline">Kembali ke halaman masuk</Link>}>
      <ResetForm />
    </AuthShell>
  );
}
