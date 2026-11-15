<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Alamat jalan domisili agen (melengkapi kode wilayah). Data pribadi → terenkripsi, tidak pernah tampil publik. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE agents ADD COLUMN address_enc text, ADD COLUMN postal_code varchar(5);');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE agents DROP COLUMN IF EXISTS address_enc, DROP COLUMN IF EXISTS postal_code;');
    }
};
