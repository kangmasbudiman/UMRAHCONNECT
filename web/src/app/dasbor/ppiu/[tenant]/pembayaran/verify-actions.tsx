'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { api, errorMessage } from '@/lib/client-api';
import { rupiah } from '@/lib/format';

/** FR-FIN-04 Verifikasi: nominal terverifikasi = sesuai mutasi rekening (boleh beda dari klaim). */
export function VerifyActions({ tenantId, paymentId, claimed, remaining }: { tenantId: string; paymentId: string; claimed: number; remaining: number }) {
  const router = useRouter();
  const [amount, setAmount] = useState(String(Math.min(claimed, remaining)));
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [key] = useState(() => crypto.randomUUID());
  const n = Number(amount.replace(/\D/g, '')) || 0;

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-end gap-2">
        <div>
          <label htmlFor={`va-${paymentId}`} className="mb-1 block text-sm font-semibold">Nominal sesuai mutasi</label>
          <input id={`va-${paymentId}`} inputMode="numeric" className="input num w-44" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </div>
        <button type="button" className="btn btn-primary" disabled={!!busy || n < 1} onClick={async () => {
          if (!confirm(`Verifikasi ${rupiah(n)}? Kwitansi langsung terbit dan status booking diperbarui.`)) return;
          setBusy('v');
          setError(null);
          try { await api(`/tenants/${tenantId}/payments/${paymentId}/verify`, { json: { verified_amount: n }, headers: { 'Idempotency-Key': key } }); router.refresh(); }
          catch (e) { setError(errorMessage(e)); setBusy(null); }
        }}>{busy === 'v' && <Spinner />}Verifikasi</button>
        <button type="button" className="btn border border-danger bg-white text-danger hover:bg-danger-bg" disabled={!!busy} onClick={async () => {
          const reason = prompt('Alasan menolak bukti bayar (ditampilkan ke jamaah):');
          if (!reason || reason.trim().length < 3) return;
          setBusy('r');
          setError(null);
          try { await api(`/tenants/${tenantId}/payments/${paymentId}/reject`, { json: { reason: reason.trim() } }); router.refresh(); }
          catch (e) { setError(errorMessage(e)); setBusy(null); }
        }}>{busy === 'r' && <Spinner />}Tolak</button>
      </div>
      {n !== claimed && n > 0 && <p className="text-sm text-warning">Berbeda dari klaim jamaah ({rupiah(claimed)}).</p>}
      {error && <p role="alert" className="text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}
