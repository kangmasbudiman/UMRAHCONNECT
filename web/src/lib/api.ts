import 'server-only';
import { cacheLife } from 'next/cache';

/**
 * Klien API marketplace publik (kontrak: api/openapi.yaml, tag Public).
 * Dipanggil hanya dari Server Component; hasil di-cache singkat karena sisa kursi bisa berubah.
 */
const API = process.env.API_BASE_URL ?? 'http://127.0.0.1:8000/api/v1';

export type Price = { room_type: 'quad' | 'triple' | 'double'; price_amount: number };
export type DeparturePublic = {
  id: string; departure_date: string; return_date: string; departure_city: string;
  seats_remaining: number; sales_status: 'open' | 'closed' | 'full';
  dp_min_per_pax_amount: number; final_payment_due_on: string; prices: Price[];
};
export type TenantPublic = {
  id: string; slug: string; brand_name: string; legal_name: string; ppiu_license_no: string; verified: boolean;
  city: string | null; province: string | null; address: string | null; phone: string | null; whatsapp: string | null; email: string | null;
};
export type PackageSummary = {
  id: string; slug: string; name: string;
  tenant: { slug: string; brand_name: string; verified: boolean; ppiu_license_no: string };
  duration_days: number; airline: string; hotel_makkah_stars: number | null; cover_image_url: string | null;
  price_from_amount: number;
  next_departure: { departure_date: string; departure_city: string; seats_remaining: number } | null;
  hotel_makkah_distance_m?: number | null; hotel_madinah_distance_m?: number | null;
  elderly_friendly?: boolean; transparency_score?: number; rating?: Rating | null; featured?: boolean;
};
export type Rating = { avg: number | null; count: number };
export type TransparencyItem = { key: string; label: string; ok: boolean; weight: number };
export type Review = {
  id: string; rating: number; body: string; author: string; status: string;
  verified_departure: { departure_date: string } | null; tenant_reply: string | null; replied_at: string | null; created_at: string;
};
export type ReviewList = { data: Review[]; meta: { next_cursor: string | null; summary: Rating & { distribution: Record<string, number> }; tenant: Rating } };
export type JourneyStep = { key: string; label: string; status: 'done' | 'current' | 'pending' | 'skipped'; date: string | null; note: string | null };
export type Journey = { state: string; progress: number; steps: JourneyStep[] };
export type Tracking = {
  code: string; status: string; participant_count: number; journey: Journey;
  package: { name: string; duration_days: number };
  tenant: { brand_name: string; slug: string; whatsapp: string | null; ppiu_license_no: string };
  departure: { departure_date: string; return_date: string; departure_city: string };
};
export type PackageDetail = {
  id: string; slug: string; name: string; duration_days: number; airline: string;
  hotel_makkah: string | null; hotel_makkah_stars: number | null; hotel_makkah_distance_m: number | null;
  hotel_madinah: string | null; hotel_madinah_stars: number | null; hotel_madinah_distance_m: number | null;
  description: string | null; itinerary: { day: number; title: string; activities?: string[] }[];
  facilities: string[]; includes: string[]; excludes: string[];
  document_requirements: string | null; payment_terms: string | null; cancellation_terms: string | null;
  tenant: TenantPublic & { rating?: Rating }; departures: DeparturePublic[];
  images: { id: string; url: string | null; sort_order: number }[];
  transparency: { score: number; items: TransparencyItem[] }; elderly_friendly: boolean; rating: Rating | null;
};
export type ScheduleItem = DeparturePublic & {
  package: { slug: string; name: string; duration_days: number; airline: string };
  tenant: { slug: string; brand_name: string };
};
export type Facets = { airlines: string[]; departure_cities: string[]; months: string[]; price_min_amount: number; price_max_amount: number };
export type Referral = {
  agent: { id: string; code: string; display_name: string; whatsapp: string | null; domicile_city: string | null };
  tenant: { id: string; slug: string; brand_name: string; whatsapp: string | null };
  package_slug: string | null;
};
type List<T> = { data: T[]; meta: { next_cursor: string | null; limit: number } };

export class ApiError extends Error {
  constructor(public status: number, public code: string, message: string) { super(message); }
}

async function get<T>(path: string): Promise<T> {
  const res = await fetch(API + path, { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    const body = await res.json().catch(() => null);
    throw new ApiError(res.status, body?.error?.code ?? 'server_error', body?.error?.message ?? 'Terjadi kendala.');
  }
  return res.json() as Promise<T>;
}

function qs(params: Record<string, string | undefined>): string {
  const u = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) if (v) u.set(k, v);
  const s = u.toString();
  return s ? `?${s}` : '';
}

export async function getPackages(params: Record<string, string | undefined>): Promise<List<PackageSummary>> {
  'use cache';
  cacheLife({ stale: 30, revalidate: 60, expire: 300 });
  return get(`/public/packages${qs(params)}`);
}

/** null bila paket tidak ada atau tidak boleh tampil (404). */
export async function getPackage(tenantSlug: string, packageSlug: string): Promise<PackageDetail | null> {
  'use cache';
  cacheLife({ stale: 30, revalidate: 60, expire: 300 });
  try {
    return (await get<{ data: PackageDetail }>(`/public/packages/${encodeURIComponent(tenantSlug)}/${encodeURIComponent(packageSlug)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

export async function getTenants(): Promise<List<TenantPublic>> {
  'use cache';
  cacheLife('minutes');
  return get('/public/tenants?limit=100');
}

export async function getTenant(slug: string): Promise<(TenantPublic & { packages: PackageSummary[]; rating?: Rating }) | null> {
  'use cache';
  cacheLife({ stale: 30, revalidate: 60, expire: 300 });
  try {
    return (await get<{ data: TenantPublic & { packages: PackageSummary[] } }>(`/public/tenants/${encodeURIComponent(slug)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

export async function getSchedule(params: Record<string, string | undefined>): Promise<List<ScheduleItem>> {
  'use cache';
  cacheLife({ stale: 30, revalidate: 60, expire: 300 });
  return get(`/public/departures${qs({ ...params, limit: '100' })}`);
}

export async function getFacets(): Promise<Facets> {
  'use cache';
  cacheLife('minutes');
  return (await get<{ data: Facets }>('/public/facets')).data;
}

/** Pengaturan publik platform (mis. jendela atribusi referral, diatur Super Admin). */
export async function getPublicConfig(): Promise<{ attribution_window_days: number }> {
  'use cache';
  cacheLife('minutes');
  try {
    return (await get<{ data: { attribution_window_days: number } }>('/public/config')).data;
  } catch {
    return { attribution_window_days: 30 };
  }
}

export async function getPackageReviews(tenantSlug: string, packageSlug: string): Promise<ReviewList | null> {
  'use cache';
  cacheLife('minutes');
  try {
    return await get<ReviewList>(`/public/packages/${encodeURIComponent(tenantSlug)}/${encodeURIComponent(packageSlug)}/reviews?limit=10`);
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

/** Lacak Umrah publik: tidak di-cache (status bisa berubah kapan saja). */
export async function trackBooking(code: string, phone: string): Promise<Tracking | null> {
  try {
    return (await get<{ data: Tracking }>(`/public/track?code=${encodeURIComponent(code)}&phone=${encodeURIComponent(phone)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && (e.status === 404 || e.status === 422)) return null;
    throw e;
  }
}

export type AgentPage = {
  code: string; display_name: string | null; domicile_city: string | null; whatsapp: string | null;
  partners: { slug: string; brand_name: string; ppiu_license_no: string; city: string | null }[];
  packages: { slug: string; name: string; duration_days: number; airline: string; tenant: { slug: string; brand_name: string }; cover_image_url: string | null;
    price_from_amount: number; next_departure: { departure_date: string; departure_city: string } | null; referral_code: string | null }[];
};

/** Halaman agen publik (Agen Premium). null bila agen tidak ada atau tidak berlangganan premium. */
export async function getAgentPage(code: string): Promise<AgentPage | null> {
  'use cache';
  cacheLife('minutes');
  try {
    return (await get<{ data: AgentPage }>(`/public/agents/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}

export async function getReferral(code: string): Promise<Referral | null> {
  'use cache';
  cacheLife('minutes');
  try {
    return (await get<{ data: Referral }>(`/public/referrals/${encodeURIComponent(code)}`)).data;
  } catch (e) {
    if (e instanceof ApiError && e.status === 404) return null;
    throw e;
  }
}
