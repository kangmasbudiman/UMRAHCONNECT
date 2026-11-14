import Link from 'next/link';
import { IconSearch } from '@/components/icons';

export default function NotFound() {
  return (
    <div className="mx-auto max-w-lg px-4 py-16">
      <div className="card p-8 text-center">
        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gold-100 text-gold-700"><IconSearch className="h-8 w-8" /></span>
        <h1 className="mt-4 text-2xl font-extrabold">Halaman tidak ditemukan</h1>
        <p className="mt-3 text-ink-600">Paket atau PPIU ini tidak ada, belum terbit, atau sedang tidak ditampilkan.</p>
        <Link href="/paket" className="btn btn-gold mt-6">Lihat paket umrah</Link>
      </div>
    </div>
  );
}
