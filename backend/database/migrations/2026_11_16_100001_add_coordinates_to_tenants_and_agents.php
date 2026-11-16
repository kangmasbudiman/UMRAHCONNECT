<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Titik lokasi (WGS84) kantor PPIU dan domisili agen untuk peta (web MapLibre & aplikasi ponsel)
 * dan pencarian "terdekat". Dibatasi ke kotak wilayah Indonesia agar salah ketik/klik tidak lolos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE tenants
  ADD COLUMN latitude  numeric(9,6),
  ADD COLUMN longitude numeric(9,6),
  ADD CONSTRAINT tenants_coordinates_chk CHECK (
    (latitude IS NULL AND longitude IS NULL)
    OR (latitude BETWEEN -11.5 AND 6.5 AND longitude BETWEEN 94.5 AND 141.5)
  );
CREATE INDEX tenants_coordinates_idx ON tenants (latitude, longitude) WHERE latitude IS NOT NULL;

ALTER TABLE agents
  ADD COLUMN latitude  numeric(9,6),
  ADD COLUMN longitude numeric(9,6),
  ADD CONSTRAINT agents_coordinates_chk CHECK (
    (latitude IS NULL AND longitude IS NULL)
    OR (latitude BETWEEN -11.5 AND 6.5 AND longitude BETWEEN 94.5 AND 141.5)
  );
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_coordinates_chk, DROP COLUMN IF EXISTS latitude, DROP COLUMN IF EXISTS longitude;
DROP INDEX IF EXISTS tenants_coordinates_idx;
ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_coordinates_chk, DROP COLUMN IF EXISTS latitude, DROP COLUMN IF EXISTS longitude;
SQL);
    }
};
