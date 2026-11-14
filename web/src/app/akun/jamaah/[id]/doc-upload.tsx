'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Spinner } from '@/components/form';
import { api, ApiClientError, errorMessage } from '@/lib/client-api';

/** Unggah/ganti satu jenis dokumen jamaah (FR-JMH-06). Dokumen lama sejenis otomatis diganti. */
export function DocUpload({ pilgrimId, type, label, hasDoc }: { pilgrimId: string; type: string; label: string; hasDoc: boolean }) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <div>
      <label className={`btn ${hasDoc ? 'btn-outline' : 'btn-primary'} min-h-10 cursor-pointer px-3 text-sm ${busy ? 'pointer-events-none opacity-70' : ''}`}>
        {busy && <Spinner />}{hasDoc ? 'Ganti' : 'Unggah'}
        <input type="file" accept="image/jpeg,image/png,application/pdf" className="sr-only" aria-label={`Unggah ${label}`} onChange={async (e) => {
          const file = e.currentTarget.files?.[0];
          e.currentTarget.value = '';
          if (!file) return;
          setBusy(true);
          setError(null);
          try {
            if (file.size > 5 * 1024 * 1024) throw new Error('Ukuran file maksimal 5 MB.');
            const fd = new FormData();
            fd.set('purpose', 'pilgrim_document');
            fd.set('file', file);
            const f = await api<{ data: { id: string } }>('/files', { form: fd });
            await api(`/me/pilgrims/${pilgrimId}/documents`, { json: { type, file_id: f.data.id } });
            router.refresh();
          } catch (err) { setError(err instanceof ApiClientError ? (err.field('file') ?? err.message) : err instanceof Error ? err.message : errorMessage(err)); }
          setBusy(false);
        }} />
      </label>
      {error && <p role="alert" className="mt-1 text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}
