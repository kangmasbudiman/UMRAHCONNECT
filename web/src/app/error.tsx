'use client';

export default function Error({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div className="mx-auto max-w-lg px-4 py-16" role="alert">
      <div className="card p-8 text-center">
        <h1 className="text-2xl font-extrabold">Terjadi kendala di sistem kami</h1>
        <p className="mt-3 text-ink-600">Data paket belum bisa dimuat. Coba lagi beberapa saat lagi.</p>
        <button onClick={reset} className="btn btn-primary mt-6">Coba lagi</button>
      </div>
    </div>
  );
}
