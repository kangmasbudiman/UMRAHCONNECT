<?php

namespace App\Support;

/**
 * Menyimpan tenant (PPIU) aktif untuk satu permintaan.
 * Diisi oleh middleware route /tenants/{tenant}; kosong di route publik dan /me, /agent.
 * Database Design §3 lapis 1: scope aplikasi.
 */
class TenantContext
{
    private ?string $tenantId = null;

    public function set(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }
}
