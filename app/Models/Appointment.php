<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = [
        'booking_reference',
        'patient_name',
        'patient_phone',
        'patient_email',
        'diagnostic_test_id',
        'clinic_slot_id',
        'amount_ngn',
        'amount_kobo',
        'status',
        'paystack_reference',
        'paystack_checkout_url',
        'paystack_access_code',
        'paystack_payment_status',
        'lab_pass_code',
        'prep_confirmed',
        'notes',
        'hold_expires_at',
        'confirmed_at',
    ];

    protected $casts = [
        'amount_ngn' => 'integer',
        'amount_kobo' => 'integer',
        'prep_confirmed' => 'boolean',
        'hold_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function diagnosticTest(): BelongsTo
    {
        return $this->belongsTo(DiagnosticTest::class);
    }

    public function clinicSlot(): BelongsTo
    {
        return $this->belongsTo(ClinicSlot::class);
    }
}
