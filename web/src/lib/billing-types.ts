export type PlanFeatures = { max_members?: number; manifest_export?: boolean; public_page?: boolean };
export type Plan = { code: string; name: string; price_monthly: number; features: PlanFeatures };
export type Subscription = { id: string; plan_code: string; status: 'active' | 'past_due' | 'cancelled'; price_override: number | null; started_at: string } | null;
export type PlatformInvoice = {
  id: string; number: string; period: string; status: 'unpaid' | 'awaiting_confirmation' | 'paid' | 'void'; total: number; due_on: string;
  issued_at: string; paid_at: string | null; overdue: boolean; payer_note: string | null; review_note: string | null; has_proof: boolean;
  items: { type: string; description: string; quantity: number; unit_amount: number; amount: number }[];
  billable_type?: string; billable_name?: string; proof_url?: string | null;
};
export type FeaturedPlacement = { id: string; status: 'active' | 'ended' | 'cancelled'; price_monthly: number; starts_at: string; ends_at: string | null; package: { id: string; name: string; slug: string } };
export type TenantBilling = {
  plan: Plan; subscription: Subscription; plans: Plan[]; usage: { members: number; max_members: number };
  transaction_fee_amount: number; accrued_fees: { count: number; amount: number };
  featured: { price_monthly: number; slots_left: number; placements: FeaturedPlacement[] };
  invoices: PlatformInvoice[]; bank_account: string;
};
export type AgentBilling = { plan: Plan; subscription: Subscription; plans: Plan[]; public_page_url: string | null; invoices: PlatformInvoice[]; bank_account: string };

export const INVOICE_STATUS: Record<PlatformInvoice['status'], string> = {
  unpaid: 'Belum dibayar', awaiting_confirmation: 'Menunggu konfirmasi', paid: 'Lunas', void: 'Dibatalkan',
};

/** Isi paket untuk ditampilkan ke pengguna. */
export function planBullets(p: Plan): string[] {
  const f = p.features;
  if (p.code.startsWith('agent')) {
    return f.public_page
      ? ['Semua fitur Agen Free', 'Halaman agen pribadi yang bisa dibagikan', 'Semua paket mitra dengan kode referral Anda', 'Tombol WhatsApp langsung ke Anda']
      : ['Kemitraan banyak PPIU', 'Link referral & QR', 'Daftar prospek', 'Pemantauan komisi'];
  }
  return [
    `Hingga ${f.max_members ?? 3} anggota tim (termasuk Owner)`,
    f.manifest_export ? 'Ekspor manifest jamaah & rooming list (CSV)' : 'Paket, booking, invoice, verifikasi transfer',
    f.manifest_export ? 'Prioritas dukungan' : 'Agen & komisi, Lacak Umrah, laporan dasar',
  ];
}
