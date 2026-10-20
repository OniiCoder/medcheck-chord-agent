<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ClinicSlot;
use App\Models\DiagnosticTest;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tests = [
            [
                'code' => 'LIPID-FAST',
                'name' => 'Fasting Lipid Profile (Cholesterol, HDL, LDL, Triglycerides)',
                'category' => 'pathology',
                'price_ngn' => 28500,
                'requires_fasting' => true,
                'morning_only' => true,
                'prep_instructions' => 'Mandatory 10 to 12 hours overnight fasting. Plain water is allowed. No coffee, tea, milk, gum, or breakfast. Sample must be drawn before 11:00 AM.',
                'turnaround_time' => 'Same day (6 hours)',
            ],
            [
                'code' => 'FBS-HBA1C',
                'name' => 'Fasting Blood Sugar (FBS) + HbA1c Diabetes Panel',
                'category' => 'pathology',
                'price_ngn' => 22000,
                'requires_fasting' => true,
                'morning_only' => true,
                'prep_instructions' => 'Mandatory 8 to 10 hours overnight fasting. Plain water only. Do not take morning oral hypoglycemic medication or insulin until blood sample is drawn.',
                'turnaround_time' => 'Same day (4 hours)',
            ],
            [
                'code' => 'FBC-MALARIA',
                'name' => 'Full Blood Count (FBC) + Malaria Parasite Microscopy',
                'category' => 'pathology',
                'price_ngn' => 14500,
                'requires_fasting' => false,
                'morning_only' => false,
                'prep_instructions' => 'No fasting required. Stay hydrated prior to sample collection.',
                'turnaround_time' => '2 hours',
            ],
            [
                'code' => 'US-PELVIC',
                'name' => 'Pelvic / Lower Abdomen Ultrasound Scan',
                'category' => 'ultrasound',
                'price_ngn' => 25000,
                'requires_fasting' => false,
                'morning_only' => false,
                'prep_instructions' => 'FULL BLADDER REQUIRED: Drink 4 glasses (approx. 1 liter) of water 1 hour before your appointment and DO NOT urinate until the scan is complete.',
                'turnaround_time' => 'Instant verbal report + signed PDF in 45 mins',
            ],
            [
                'code' => 'US-ABDOMEN',
                'name' => 'Upper Abdominal Ultrasound (Liver, Gallbladder, Kidneys)',
                'category' => 'ultrasound',
                'price_ngn' => 30000,
                'requires_fasting' => true,
                'morning_only' => true,
                'prep_instructions' => 'Fast for 6 to 8 hours before the scan so the gallbladder remains distended and bowel gas is minimized. Sips of water for medication are permitted.',
                'turnaround_time' => 'Signed radiologist report in 45 mins',
            ],
            [
                'code' => 'MRI-BRAIN',
                'name' => 'Brain MRI (Non-Contrast 1.5T)',
                'category' => 'radiology_mri',
                'price_ngn' => 165000,
                'requires_fasting' => false,
                'morning_only' => false,
                'prep_instructions' => 'SAFETY SCREENING MANDATORY: Patients with cardiac pacemakers, cochlear implants, aneurysm clips, or metallic eye fragments CANNOT enter the MRI suite. Remove all jewelry, watches, wigs with metal clips, and credit cards.',
                'turnaround_time' => 'Consultant Radiologist report within 24 hours',
            ],
            [
                'code' => 'MRI-LUMBOSACRAL',
                'name' => 'Lumbosacral Spine MRI (Lower Back 1.5T)',
                'category' => 'radiology_mri',
                'price_ngn' => 155000,
                'requires_fasting' => false,
                'morning_only' => false,
                'prep_instructions' => 'SAFETY SCREENING MANDATORY: Confirm no cardiac pacemaker, neurostimulator, or non-titanium ferromagnetic implants. Wear metal-free clothing; hospital gown provided.',
                'turnaround_time' => 'Consultant Radiologist report within 24 hours',
            ],
            [
                'code' => 'ECHO-ECG',
                'name' => '12-Lead Resting ECG + Transthoracic Echocardiogram',
                'category' => 'cardiology',
                'price_ngn' => 65000,
                'requires_fasting' => false,
                'morning_only' => false,
                'prep_instructions' => 'No fasting required. Wear a two-piece outfit (separate top) and avoid applying heavy body lotions or oils to the chest area on the morning of the test.',
                'turnaround_time' => 'Cardiologist review within 4 hours',
            ],
        ];

        $testModels = [];
        foreach ($tests as $test) {
            $testModels[$test['code']] = DiagnosticTest::updateOrCreate(
                ['code' => $test['code']],
                $test
            );
        }

        $baseDate = Carbon::parse('2026-10-19');
        $slotTemplates = [
            // Pathology (Phlebotomy Bay)
            ['modality' => 'pathology', 'start' => '07:30', 'end' => '08:00', 'capacity' => 3],
            ['modality' => 'pathology', 'start' => '08:30', 'end' => '09:00', 'capacity' => 3],
            ['modality' => 'pathology', 'start' => '09:30', 'end' => '10:00', 'capacity' => 3],
            ['modality' => 'pathology', 'start' => '10:30', 'end' => '11:00', 'capacity' => 2],
            ['modality' => 'pathology', 'start' => '13:30', 'end' => '14:00', 'capacity' => 3],
            ['modality' => 'pathology', 'start' => '15:00', 'end' => '15:30', 'capacity' => 3],

            // Ultrasound Suite
            ['modality' => 'ultrasound', 'start' => '08:30', 'end' => '09:15', 'capacity' => 1],
            ['modality' => 'ultrasound', 'start' => '10:00', 'end' => '10:45', 'capacity' => 2],
            ['modality' => 'ultrasound', 'start' => '12:00', 'end' => '12:45', 'capacity' => 2],
            ['modality' => 'ultrasound', 'start' => '14:30', 'end' => '15:15', 'capacity' => 2],

            // 1.5T MRI Suite
            ['modality' => 'radiology_mri', 'start' => '09:00', 'end' => '10:00', 'capacity' => 1],
            ['modality' => 'radiology_mri', 'start' => '11:30', 'end' => '12:30', 'capacity' => 1],
            ['modality' => 'radiology_mri', 'start' => '14:00', 'end' => '15:00', 'capacity' => 1],
            ['modality' => 'radiology_mri', 'start' => '16:00', 'end' => '17:00', 'capacity' => 1],

            // Cardiology Suite
            ['modality' => 'cardiology', 'start' => '09:30', 'end' => '10:30', 'capacity' => 1],
            ['modality' => 'cardiology', 'start' => '12:00', 'end' => '13:00', 'capacity' => 1],
            ['modality' => 'cardiology', 'start' => '15:00', 'end' => '16:00', 'capacity' => 1],
        ];

        $firstTomorrowPathSlot = null;
        $secondTomorrowUltrasoundSlot = null;

        for ($dayOffset = 0; $dayOffset <= 6; $dayOffset++) {
            $dateStr = $baseDate->copy()->addDays($dayOffset)->format('Y-m-d');
            $compactDate = str_replace('-', '', $dateStr);

            foreach ($slotTemplates as $tpl) {
                $compactTime = str_replace(':', '', $tpl['start']);
                $modShort = strtoupper(substr($tpl['modality'], 0, 4));
                $slotCode = "SLOT-{$compactDate}-{$compactTime}-{$modShort}";

                // Make 08:30 ultrasound tomorrow already full so we can demonstrate full-slot handling
                $bookedCount = ($dayOffset === 1 && $tpl['modality'] === 'ultrasound' && $tpl['start'] === '08:30')
                    ? $tpl['capacity']
                    : 0;

                $slot = ClinicSlot::updateOrCreate(
                    ['slot_code' => $slotCode],
                    [
                        'slot_date' => $dateStr,
                        'start_time' => $tpl['start'],
                        'end_time' => $tpl['end'],
                        'modality' => $tpl['modality'],
                        'capacity' => $tpl['capacity'],
                        'booked_count' => $bookedCount,
                    ]
                );

                if ($dayOffset === 1 && $tpl['modality'] === 'pathology' && $tpl['start'] === '07:30') {
                    $firstTomorrowPathSlot = $slot;
                }
                if ($dayOffset === 1 && $tpl['modality'] === 'ultrasound' && $tpl['start'] === '10:00') {
                    $secondTomorrowUltrasoundSlot = $slot;
                }
            }
        }

        // Seed 2 realistic demo appointments for instant lookup / verification testing
        if ($firstTomorrowPathSlot) {
            Appointment::updateOrCreate(
                ['booking_reference' => 'MCK-20261019-1001'],
                [
                    'patient_name' => 'Chinedu Okafor',
                    'patient_phone' => '+2348031234567',
                    'patient_email' => 'chinedu.okafor@example.com',
                    'diagnostic_test_id' => $testModels['LIPID-FAST']->id,
                    'clinic_slot_id' => $firstTomorrowPathSlot->id,
                    'amount_ngn' => 28500,
                    'amount_kobo' => 2850000,
                    'status' => 'CONFIRMED',
                    'paystack_reference' => 'PSK-MCK-1001-PAID',
                    'paystack_checkout_url' => 'https://checkout.paystack.com/test_paid_1001',
                    'paystack_access_code' => 'acc_test_1001',
                    'paystack_payment_status' => 'success',
                    'lab_pass_code' => 'MCK-PASS-4821',
                    'prep_confirmed' => true,
                    'notes' => 'Confirmed 12hr overnight fast.',
                    'hold_expires_at' => Carbon::now()->addHours(2),
                    'confirmed_at' => Carbon::now()->subMinutes(20),
                ]
            );
        }

        if ($secondTomorrowUltrasoundSlot) {
            Appointment::updateOrCreate(
                ['booking_reference' => 'MCK-20261019-1002'],
                [
                    'patient_name' => 'Amina Bello',
                    'patient_phone' => '+2348098765432',
                    'patient_email' => 'amina.bello@example.com',
                    'diagnostic_test_id' => $testModels['US-PELVIC']->id,
                    'clinic_slot_id' => $secondTomorrowUltrasoundSlot->id,
                    'amount_ngn' => 25000,
                    'amount_kobo' => 2500000,
                    'status' => 'PAYMENT_FAILED',
                    'paystack_reference' => 'PSK-MCK-1002-DECLINED',
                    'paystack_checkout_url' => 'https://checkout.paystack.com/test_retry_1002',
                    'paystack_access_code' => 'acc_test_1002',
                    'paystack_payment_status' => 'failed',
                    'lab_pass_code' => null,
                    'prep_confirmed' => true,
                    'notes' => 'Card declined by issuer (Insufficient Funds in test mode).',
                    'hold_expires_at' => Carbon::now()->addMinutes(30),
                    'confirmed_at' => null,
                ]
            );
        }
    }
}
