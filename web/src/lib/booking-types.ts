import type { Journey } from './api';
/** Bentuk respons booking (api/openapi.yaml: Booking, BookingAgentView, Invoice). */
export type PayTo = { bank_name: string; account_no: string; account_holder: string };
export type Invoice = {
  id: string; number: string; booking_id: string; booking_code: string | null; type: 'dp' | 'final' | 'full';
  amount: number; paid_amount: number; due_at: string; status: 'unpaid' | 'partial' | 'paid' | 'cancelled'; pay_to: PayTo | null;
};
export type BookingStatus = 'awaiting_dp' | 'dp_verified' | 'fully_paid' | 'departed' | 'completed' | 'expired' | 'cancelled';
export type DocReview = { tenant_id: string; tenant_name: string | null; status: 'accepted' | 'rejected'; note: string | null; reviewed_at: string | null };
export type PilgrimDoc = { id: string; pilgrim_id: string; type: 'ktp' | 'passport' | 'photo' | 'vaccine' | 'other'; file_id: string; file_name: string | null; status: string; created_at: string | null; reviews: DocReview[] };
export type BookingParticipant = {
  pilgrim_id?: string; full_name: string; gender?: 'L' | 'P'; room_type: 'quad' | 'triple' | 'double'; price_amount?: number;
  nik_masked?: string; passport_no_masked?: string | null; birth_date?: string; documents?: PilgrimDoc[];
};
export type Payment = {
  id: string; invoice_id: string; invoice_number: string | null; invoice_type: Invoice['type'] | null; method: 'bank_transfer' | 'cash' | 'reallocation' | 'gateway';
  claimed_amount: number; verified_amount: number | null; paid_on: string | null; sender_bank?: string | null; sender_name: string | null;
  proof_url?: string | null; status: 'pending' | 'verified' | 'rejected'; reject_reason: string | null; split_from_payment_id: string | null;
  receipt: { id: string; number: string } | null; created_at: string | null; verified_at: string | null;
};
export type Refund = { id: string; type: 'bank_transfer' | 'reallocation'; amount: number; target_booking_code: string | null; paid_on: string; note: string; created_at: string | null };
export type Booking = {
  id: string; code: string; status: BookingStatus;
  tenant: { id: string; slug: string; brand_name: string; whatsapp: string | null };
  package: { id: string; name: string; slug: string; duration_days: number; airline: string };
  departure: { id: string; departure_date: string; return_date: string; departure_city: string; final_payment_due_on: string };
  booker: { name: string; phone: string | null };
  agent?: { id: string; code: string; display_name: string; phone?: string | null } | null;
  attribution_source: string; channel?: string; participant_count: number; participants: BookingParticipant[];
  total_amount: number; dp_amount?: number; paid_amount: number; hold_expires_at: string | null;
  invoices: Invoice[]; pay_to?: PayTo | null; created_at: string | null; cancel_reason?: string | null;
  payments?: Payment[]; refunds?: Refund[]; refund_total_amount?: number; refund_decided_amount?: number | null; refund_basis?: string | null;
  cancel_request?: { status: 'pending' | 'approved' | 'rejected'; requested_at: string | null; reason: string | null } | null;
  cancel_request_status?: 'pending' | 'approved' | 'rejected' | null; notes_count?: number;
  commission_snapshot?: { type: 'flat' | 'percent'; flat_amount: number | null; percent_bp: number | null; earn_on: string } | null;
  journey?: Journey;
  review?: { id: string; rating: number; body: string; status: string; tenant_reply: string | null; created_at: string } | null;
  can_review?: boolean;
};
export type BookingSummary = {
  id: string; code: string; status: BookingStatus; tenant: { id: string; brand_name: string; slug: string };
  package: { id: string; name: string }; departure: { id: string; departure_date: string; departure_city: string };
  booker: { name: string; phone: string | null }; participant_count: number; total_amount: number; dp_amount: number; paid_amount: number;
  hold_expires_at: string | null; channel: string; attribution_source: string; created_at: string | null;
  agent?: { id: string; code: string; display_name: string } | null;
};
export type SavedPilgrim = { id: string; full_name: string; nik_masked: string; birth_date: string; gender: 'L' | 'P' };
export type WizardDeparture = {
  id: string; departure_date: string; return_date: string; departure_city: string; seats_remaining: number;
  sales_status: 'open' | 'closed' | 'full'; dp_min_per_pax_amount: number; final_payment_due_on: string;
  dp_deadline_hours?: number; prices: { room_type: 'quad' | 'triple' | 'double'; price_amount: number }[];
};

export const DOC_TYPE: Record<PilgrimDoc['type'], string> = { ktp: 'KTP', passport: 'Paspor', photo: 'Pas foto', vaccine: 'Kartu vaksin meningitis', other: 'Dokumen lain' };
export const PAY_METHOD: Record<Payment['method'], string> = { bank_transfer: 'Transfer bank', cash: 'Tunai', reallocation: 'Pengalihan dana', gateway: 'Payment gateway' };
export const PAY_STATUS: Record<Payment['status'], string> = { pending: 'Menunggu verifikasi', verified: 'Terverifikasi', rejected: 'Ditolak' };

export const BOOKING_STATUS: Record<BookingStatus, string> = {
  awaiting_dp: 'Menunggu DP', dp_verified: 'DP terverifikasi', fully_paid: 'Lunas', departed: 'Berangkat',
  completed: 'Selesai', expired: 'Kedaluwarsa', cancelled: 'Dibatalkan',
};
export const INVOICE_TYPE: Record<Invoice['type'], string> = { dp: 'Uang muka (DP)', final: 'Pelunasan', full: 'Pembayaran penuh' };
export const ATTRIBUTION: Record<string, string> = {
  agent_created: 'Dibuat agen', ppiu_assigned: 'Ditugaskan PPIU', manual_code: 'Kode agen diketik', referral_link: 'Link agen', none: 'Tanpa agen',
};
