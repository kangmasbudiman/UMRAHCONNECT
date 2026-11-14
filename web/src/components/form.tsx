import type { ReactNode } from 'react';

/** Label di atas input, petunjuk dan pesan error di bawah (UI/UX Design §2: form). */
export function Field({ id, label, hint, error, children, optional }: {
  id: string; label: string; hint?: string; error?: string; children: ReactNode; optional?: boolean;
}) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block font-semibold">
        {label}{optional && <span className="font-normal text-ink-600"> (opsional)</span>}
      </label>
      {children}
      {hint && !error && <p id={`${id}-hint`} className="mt-1 text-sm text-ink-600">{hint}</p>}
      {error && <p id={`${id}-error`} role="alert" className="mt-1 text-sm font-semibold text-danger">{error}</p>}
    </div>
  );
}

const TONES = {
  danger: 'border-danger bg-danger-bg text-danger',
  success: 'border-success bg-success-bg text-success',
  warning: 'border-warning bg-warning-bg text-warning',
  info: 'border-info bg-info-bg text-info',
} as const;

export function Alert({ tone = 'info', title, children }: { tone?: keyof typeof TONES; title?: string; children?: ReactNode }) {
  return (
    <div role={tone === 'danger' ? 'alert' : 'status'} className={`rounded-[10px] border px-4 py-3 ${TONES[tone]}`}>
      {title && <p className="font-bold">{title}</p>}
      {children && <div className={title ? 'mt-1 text-ink-900' : 'text-ink-900'}>{children}</div>}
    </div>
  );
}

/** Kartu formulir di tengah layar untuk halaman masuk/daftar. */
export function AuthShell({ title, sub, children, footer }: { title: string; sub?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <div className="bg-hero">
      <div className="mx-auto flex min-h-[calc(100vh-4rem)] max-w-6xl items-start justify-center px-4 py-10 md:py-16">
        <div className="card w-full max-w-md p-6 md:p-8">
          <h1 className="text-2xl font-extrabold leading-8">{title}</h1>
          {sub && <div className="mt-1 text-ink-600">{sub}</div>}
          <div className="mt-6">{children}</div>
          {footer && <div className="mt-6 border-t border-border-subtle pt-4 text-center text-ink-600">{footer}</div>}
        </div>
      </div>
    </div>
  );
}

export function Spinner() {
  return <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" />;
}

export const STATUS_LABEL: Record<string, { label: string; tone: keyof typeof TONES }> = {
  draft: { label: 'Draf', tone: 'info' },
  pending_review: { label: 'Menunggu tinjauan', tone: 'warning' },
  needs_revision: { label: 'Perlu revisi', tone: 'danger' },
  verified: { label: 'Terverifikasi', tone: 'success' },
  rejected: { label: 'Ditolak', tone: 'danger' },
  suspended: { label: 'Ditangguhkan', tone: 'danger' },
  pending: { label: 'Menunggu verifikasi', tone: 'warning' },
  inactive: { label: 'Nonaktif', tone: 'info' },
  submitted: { label: 'Menunggu diperiksa', tone: 'warning' },
  accepted: { label: 'Diterima', tone: 'success' },
  active: { label: 'Aktif', tone: 'success' },
  invited: { label: 'Diundang', tone: 'warning' },
  deactivated: { label: 'Nonaktif', tone: 'info' },
  published: { label: 'Terbit', tone: 'success' },
  archived: { label: 'Arsip', tone: 'info' },
  taken_down: { label: 'Diturunkan', tone: 'danger' },
  open: { label: 'Penjualan dibuka', tone: 'success' },
  closed: { label: 'Penjualan ditutup', tone: 'info' },
  full: { label: 'Penuh', tone: 'danger' },
  scheduled: { label: 'Terjadwal', tone: 'info' },
  departed: { label: 'Berangkat', tone: 'info' },
  completed: { label: 'Selesai', tone: 'success' },
  cancelled: { label: 'Dibatalkan', tone: 'danger' },
  applied: { label: 'Menunggu persetujuan', tone: 'warning' },
  ended: { label: 'Berakhir', tone: 'info' },
  awaiting_dp: { label: 'Menunggu DP', tone: 'warning' },
  dp_verified: { label: 'DP terverifikasi', tone: 'success' },
  fully_paid: { label: 'Lunas', tone: 'success' },
  expired: { label: 'Kedaluwarsa', tone: 'info' },
  unpaid: { label: 'Belum dibayar', tone: 'warning' },
  partial: { label: 'Dibayar sebagian', tone: 'warning' },
  paid: { label: 'Lunas', tone: 'success' },
  pay_pending: { label: 'Menunggu verifikasi', tone: 'warning' },
  pay_verified: { label: 'Terverifikasi', tone: 'success' },
  pay_rejected: { label: 'Ditolak', tone: 'danger' },
  com_estimated: { label: 'Perkiraan', tone: 'info' },
  com_earned: { label: 'Diperoleh', tone: 'warning' },
  com_approved: { label: 'Disetujui', tone: 'success' },
  com_paid: { label: 'Dibayar', tone: 'success' },
  com_cancelled: { label: 'Batal', tone: 'info' },
  po_requested: { label: 'Menunggu dibayar', tone: 'warning' },
  po_paid: { label: 'Dibayar', tone: 'success' },
  po_rejected: { label: 'Ditolak', tone: 'danger' },
};

export function StatusPill({ status }: { status: string }) {
  const s = STATUS_LABEL[status] ?? { label: status, tone: 'info' as const };
  return <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-sm font-semibold ${TONES[s.tone]}`}>{s.label}</span>;
}
