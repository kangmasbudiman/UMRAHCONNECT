<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Tahap persiapan keberangkatan yang diperbarui PPIU (Lacak Umrah). */
class DepartureMilestone extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPES = [
        'visa_submitted' => 'Visa diajukan',
        'visa_issued' => 'Visa terbit',
        'ticket_issued' => 'Tiket pesawat terbit',
        'hotel_confirmed' => 'Hotel terkonfirmasi',
        'manasik' => 'Manasik',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['happened_on' => 'date'];
    }
}
