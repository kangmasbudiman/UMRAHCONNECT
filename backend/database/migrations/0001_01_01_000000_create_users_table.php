<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database Design v1.2 — migrasi langkah 1: ekstensi, users, sessions, files, settings.
 * Tipe uang bigint Rupiah, waktu timestamptz, status varchar + CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE TABLE users (
  id                            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name                          varchar(150) NOT NULL,
  email                         citext UNIQUE,
  phone                         varchar(20) UNIQUE,
  password_hash                 varchar(255),
  email_verified_at             timestamptz,
  phone_verified_at             timestamptz,
  two_factor_secret_enc         text,
  two_factor_recovery_codes_enc text,
  two_factor_enabled            boolean NOT NULL DEFAULT false,
  platform_role                 varchar(20) CHECK (platform_role IN ('super_admin', 'platform_admin')),
  status                        varchar(30) NOT NULL DEFAULT 'active'
                                CHECK (status IN ('unclaimed', 'active', 'locked', 'deactivated')),
  failed_login_count            integer NOT NULL DEFAULT 0,
  locked_until                  timestamptz,
  last_login_at                 timestamptz,
  remember_token                varchar(100),
  created_at                    timestamptz NOT NULL DEFAULT now(),
  updated_at                    timestamptz NOT NULL DEFAULT now(),
  deleted_at                    timestamptz,
  CHECK (email IS NOT NULL OR phone IS NOT NULL),
  CHECK (status = 'unclaimed' OR password_hash IS NOT NULL),
  CHECK (status <> 'unclaimed' OR phone IS NOT NULL)
);

CREATE TABLE sessions (
  id            varchar(255) PRIMARY KEY,
  user_id       uuid,
  ip_address    varchar(45),
  user_agent    text,
  payload       text NOT NULL,
  last_activity integer NOT NULL
);
CREATE INDEX sessions_user_id_index ON sessions (user_id);
CREATE INDEX sessions_last_activity_index ON sessions (last_activity);

CREATE TABLE files (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id       uuid,
  uploaded_by     uuid REFERENCES users (id),
  visibility      varchar(10) NOT NULL CHECK (visibility IN ('public', 'private')),
  disk            varchar(30) NOT NULL,
  path            varchar(500) NOT NULL,
  original_name   varchar(255),
  mime_type       varchar(100) NOT NULL,
  size_bytes      integer NOT NULL CHECK (size_bytes >= 0),
  checksum_sha256 char(64),
  purpose         varchar(30) NOT NULL CHECK (purpose IN ('package_image', 'logo', 'tenant_document', 'ktp',
                    'pilgrim_document', 'payment_proof', 'refund_proof', 'payout_proof', 'invoice_pdf',
                    'receipt_pdf', 'report_export')),
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  deleted_at      timestamptz
);

CREATE TABLE settings (
  key        varchar(100) PRIMARY KEY,
  value      jsonb NOT NULL,
  updated_by uuid REFERENCES users (id),
  updated_at timestamptz NOT NULL DEFAULT now()
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS settings, files, sessions, users CASCADE;');
    }
};
