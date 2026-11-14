<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fitur unggulan pembeda (analisis pesaing 2026-11-14):
 * - departure_milestones: "Lacak Umrah", tahap persiapan per keberangkatan yang diperbarui PPIU.
 * - reviews: ulasan hanya dari pemesan yang benar-benar berangkat lewat platform.
 * - savings_plans / savings_entries: rencana tabungan umrah (pencatat; uang tetap di tangan jamaah).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE departure_milestones (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id    uuid NOT NULL REFERENCES tenants (id),
  departure_id uuid NOT NULL,
  type         varchar(30) NOT NULL CHECK (type IN ('visa_submitted', 'visa_issued', 'ticket_issued', 'hotel_confirmed', 'manasik')),
  status       varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'done')),
  happened_on  date,
  note         varchar(500),
  updated_by   uuid REFERENCES users (id),
  created_at   timestamptz NOT NULL DEFAULT now(),
  updated_at   timestamptz NOT NULL DEFAULT now(),
  UNIQUE (departure_id, type),
  FOREIGN KEY (tenant_id, departure_id) REFERENCES departures (tenant_id, id)
);

CREATE TABLE reviews (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id     uuid NOT NULL REFERENCES tenants (id),
  booking_id    uuid NOT NULL UNIQUE,
  package_id    uuid NOT NULL,
  user_id       uuid NOT NULL REFERENCES users (id),
  rating        smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body          text NOT NULL CHECK (char_length(body) BETWEEN 20 AND 2000),
  status        varchar(20) NOT NULL DEFAULT 'published' CHECK (status IN ('published', 'hidden')),
  hidden_reason text,
  tenant_reply  text,
  replied_at    timestamptz,
  replied_by    uuid REFERENCES users (id),
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now(),
  UNIQUE (tenant_id, id),
  FOREIGN KEY (tenant_id, booking_id) REFERENCES bookings (tenant_id, id),
  FOREIGN KEY (tenant_id, package_id) REFERENCES packages (tenant_id, id),
  CHECK ((status = 'hidden') = (hidden_reason IS NOT NULL))
);
CREATE INDEX reviews_package_idx ON reviews (package_id, status, created_at DESC);
CREATE INDEX reviews_tenant_idx ON reviews (tenant_id, status, created_at DESC);

CREATE TABLE savings_plans (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id        uuid NOT NULL REFERENCES users (id),
  package_id     uuid REFERENCES packages (id),
  label          varchar(150) NOT NULL,
  persons        smallint NOT NULL CHECK (persons BETWEEN 1 AND 20),
  target_amount  bigint NOT NULL CHECK (target_amount > 0),
  target_date    date NOT NULL,
  saved_amount   bigint NOT NULL DEFAULT 0 CHECK (saved_amount >= 0),
  monthly_amount bigint NOT NULL DEFAULT 0 CHECK (monthly_amount >= 0),
  reminder_day   smallint CHECK (reminder_day BETWEEN 1 AND 28),
  status         varchar(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'reached', 'archived')),
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX savings_plans_user_idx ON savings_plans (user_id, status);
CREATE INDEX savings_plans_reminder_idx ON savings_plans (reminder_day) WHERE status = 'active';

CREATE TABLE savings_entries (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  plan_id    uuid NOT NULL REFERENCES savings_plans (id) ON DELETE CASCADE,
  amount     bigint NOT NULL CHECK (amount <> 0),
  saved_on   date NOT NULL,
  note       varchar(200),
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX savings_entries_plan_idx ON savings_entries (plan_id, saved_on DESC);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS savings_entries, savings_plans, reviews, departure_milestones CASCADE;');
    }
};
