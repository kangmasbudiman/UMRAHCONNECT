<?php

namespace App\Console\Commands;

use App\Services\BookingExpiry;
use Illuminate\Console\Command;

/** JOB-01: lepas kursi booking yang tenggat DP-nya lewat. Dijadwalkan tiap menit. */
class ExpireBookingHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Kedaluwarsakan booking menunggu DP yang melewati tenggat dan lepas kursinya (JOB-01)';

    public function handle(BookingExpiry $expiry): int
    {
        $n = $expiry->expireAllDue();
        $this->info("{$n} booking kedaluwarsa.");

        return self::SUCCESS;
    }
}
