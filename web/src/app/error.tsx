'use client';

import { IconRefresh, IconWarning } from '@/components/icons';

export default function Error({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div className="mx-auto max-w-lg px-4 py-16" role="alert">
      <div className="card p-8 text-center">
        <span className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-warning-bg text-warning"><IconWarning className="h-8 w-8" /></span>
        <h1 className="mt-4 text-2xl font-extrabold">Terjadi kendala di sistem kami</h1>
        <p className="mt-3 text-ink-600">Data paket belum bisa dimuat. Coba lagi beberapa saat lagi.</p>
        <button onClick={reset} className="btn btn-primary mt-6"><IconRefresh className="h-5 w-5" />Coba lagi</button>
      </div>
    </div>
  );
}
