<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Produksi hanya menerima pengaturan awal; data contoh (DemoSeeder) tidak pernah masuk produksi. */
    public function run(): void
    {
        $this->call(ProductionSeeder::class);
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
