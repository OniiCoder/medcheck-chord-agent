<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicSlot extends Model
{
    protected $fillable = [
        'slot_code',
        'slot_date',
        'start_time',
        'end_time',
        'modality',
        'capacity',
        'booked_count',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'booked_count' => 'integer',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function availableSpots(): int
    {
        return max(0, $this->capacity - $this->booked_count);
    }
}
