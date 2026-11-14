'use client';

import Form from 'next/form';
import { useState } from 'react';
import { IconCalendar, IconPin, IconSearch, IconWallet } from './icons';

const KATEGORI = [
  { value: '', label: 'Semua paket' },
  { value: 'Reguler', label: 'Reguler' },
  { value: 'Ramadhan', label: 'Ramadhan' },
  { value: 'Plus', label: 'Umrah Plus' },
] as const;

type Option = { value: string; label: string };

/**
 * Kartu pencarian beranda. Tab kategori adalah kata kunci siap pakai:
 * bila kolom "Cari" kosong, kategori dikirim sebagai `q` ke /paket.
 */
export function HeroSearch({ months, cities, budgets }: { months: Option[]; cities: string[]; budgets: Option[] }) {
  const [kategori, setKategori] = useState('');
  const [q, setQ] = useState('');

  return (
    <div className="card overflow-hidden shadow-raised">
      <div role="group" aria-label="Kategori paket" className="no-scrollbar flex gap-1 overflow-x-auto border-b border-border-subtle bg-white px-3 pt-3">
        {KATEGORI.map((k) => {
          const active = kategori === k.value;
          return (
            <button key={k.label} type="button" aria-pressed={active} onClick={() => setKategori(k.value)}
              className={`relative min-h-12 shrink-0 rounded-t-lg px-4 font-bold transition ${active ? 'text-primary-700' : 'text-ink-600 hover:text-primary-700'}`}>
              {k.label}
              {active && <span aria-hidden="true" className="absolute inset-x-3 bottom-0 h-1 rounded-full bg-gold-500" />}
            </button>
          );
        })}
      </div>
      <Form action="/paket" className="grid gap-3 p-4 md:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr_auto] lg:items-end">
        <input type="hidden" name="q" value={q.trim() || kategori} />
        <Field icon={<IconSearch />} label="Cari" htmlFor="hero-q">
          <input id="hero-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Nama paket, PPIU, hotel" className="input pl-10" />
        </Field>
        <Field icon={<IconCalendar />} label="Bulan keberangkatan" htmlFor="hero-month">
          <select id="hero-month" name="month" defaultValue="" className="input pl-10">
            <option value="">Semua bulan</option>
            {months.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </Field>
        <Field icon={<IconPin />} label="Berangkat dari" htmlFor="hero-city">
          <select id="hero-city" name="departure_city" defaultValue="" className="input pl-10">
            <option value="">Semua kota</option>
            {cities.map((c) => <option key={c}>{c}</option>)}
          </select>
        </Field>
        <Field icon={<IconWallet />} label="Budget per orang" htmlFor="hero-budget">
          <select id="hero-budget" name="price_max" defaultValue="" className="input pl-10">
            <option value="">Semua harga</option>
            {budgets.map((b) => <option key={b.value} value={b.value}>{b.label}</option>)}
          </select>
        </Field>
        <button type="submit" className="btn btn-gold md:col-span-2 lg:col-span-1">
          <IconSearch />Cari paket
        </button>
      </Form>
    </div>
  );
}

function Field({ icon, label, htmlFor, children }: { icon: React.ReactNode; label: string; htmlFor: string; children: React.ReactNode }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="mb-1 block text-sm font-bold text-ink-900">{label}</label>
      <div className="relative">
        <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-primary-700">{icon}</span>
        {children}
      </div>
    </div>
  );
}
