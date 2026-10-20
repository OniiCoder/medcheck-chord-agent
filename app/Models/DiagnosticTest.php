<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticTest extends Model
{
    protected $fillable = [
        'code',
        'name',
        'category',
        'price_ngn',
        'requires_fasting',
        'morning_only',
        'prep_instructions',
        'turnaround_time',
    ];

    protected $casts = [
        'requires_fasting' => 'boolean',
        'morning_only' => 'boolean',
        'price_ngn' => 'integer',
    ];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
