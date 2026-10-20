<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_tests', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // e.g. LIPID-FAST, PELVIC-US, MRI-BRAIN
            $table->string('name');
            $table->string('category'); // pathology, ultrasound, radiology_mri, cardiology
            $table->unsignedBigInteger('price_ngn'); // e.g. 28500
            $table->boolean('requires_fasting')->default(false);
            $table->boolean('morning_only')->default(false);
            $table->text('prep_instructions');
            $table->string('turnaround_time');
            $table->timestamps();
        });

        Schema::create('clinic_slots', function (Blueprint $table) {
            $table->id();
            $table->string('slot_code')->unique(); // e.g. SLOT-20261020-0800-PATH
            $table->date('slot_date');
            $table->string('start_time'); // e.g. 08:00
            $table->string('end_time');   // e.g. 08:30
            $table->string('modality');   // pathology, ultrasound, radiology_mri, cardiology
            $table->unsignedInteger('capacity')->default(2);
            $table->unsignedInteger('booked_count')->default(0);
            $table->timestamps();

            $table->index(['slot_date', 'modality']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique(); // e.g. MCK-20261019-8492
            $table->string('patient_name');
            $table->string('patient_phone');
            $table->string('patient_email');
            $table->foreignId('diagnostic_test_id')->constrained('diagnostic_tests');
            $table->foreignId('clinic_slot_id')->constrained('clinic_slots');
            $table->unsignedBigInteger('amount_ngn');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status')->default('PENDING_PAYMENT'); // PENDING_PAYMENT, CONFIRMED, PAYMENT_FAILED, CANCELLED
            $table->string('paystack_reference')->nullable()->unique();
            $table->string('paystack_checkout_url')->nullable();
            $table->string('paystack_access_code')->nullable();
            $table->string('paystack_payment_status')->default('unpaid'); // unpaid, success, failed, abandoned
            $table->string('lab_pass_code')->nullable(); // Issued only after verified payment, e.g. MCK-PASS-7391
            $table->boolean('prep_confirmed')->default(false);
            $table->string('notes')->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('rpc_method');
            $table->string('tool_name')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('http_status')->default(200);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('caller_ip')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('clinic_slots');
        Schema::dropIfExists('diagnostic_tests');
    }
};
