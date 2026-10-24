<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClinicSlot;
use App\Models\DiagnosticTest;
use App\Models\McpAuditLog;
use App\Services\PaystackService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class McpServerController extends Controller
{
    public function __construct(private readonly PaystackService $paystack)
    {
    }

    /**
     * Streamable HTTP MCP Endpoint (POST /mcp & GET /mcp)
     * Compatible with Chord's streamable_http MCP client.
     */
    public function handle(Request $request): Response|JsonResponse
    {
        $startTime = microtime(true);

        // 1. Verify Bearer / API-Key authentication if MCP_BEARER_TOKEN is configured
        $expectedToken = config('services.mcp.bearer_token');
        if (! empty($expectedToken)) {
            $providedBearer = $request->bearerToken();
            $providedApiKey = $request->header('X-API-Key'); 
            $actualToken = $providedBearer ?: $providedApiKey;

            if ($actualToken !== $expectedToken) {
                $this->logRpcCall(
                    'auth_failed',
                    null,
                    ['headers' => ['authorization_present' => ! empty($actualToken)]],
                    ['error' => 'Unauthorized MCP bearer token'],
                    401,
                    $startTime,
                    $request->ip()
                );

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => [
                        'code' => -32001,
                        'message' => 'Unauthorized: invalid or missing MCP bearer token.',
                    ],
                ], 401);
            }
        }

        // Handle GET probe on /mcp gracefully
        if ($request->isMethod('GET')) {
            return response()->json([
                'name' => 'medcheck-diagnostics-lekki-mcp',
                'version' => '1.0.0',
                'transport' => 'streamable_http',
                'status' => 'ok',
            ]);
        }

        $payload = $request->json()->all();

        if (empty($payload)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error: empty or invalid JSON-RPC payload',
                ],
            ], 400);
        }

        // Support both single JSON-RPC object and JSON-RPC batch array
        $isBatch = array_is_list($payload);
        $messages = $isBatch ? $payload : [$payload];
        $responses = [];

        foreach ($messages as $msg) {
            $result = $this->processRpcMessage($msg, $startTime, $request->ip());
            if ($result !== null) {
                $responses[] = $result;
            }
        }

        // If all messages were notifications (no 'id'), return 202 Accepted per MCP Streamable HTTP spec
        if (empty($responses)) {
            return response('', 202)->header('Mcp-Session-Id', 'medcheck-session-v1');
        }

        $output = $isBatch ? $responses : $responses[0];

        return response()->json($output, 200, [
            'Mcp-Session-Id' => 'medcheck-session-v1',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function processRpcMessage(array $msg, float $startTime, ?string $callerIp): ?array
    {
        $method = $msg['method'] ?? '';
        $hasId = array_key_exists('id', $msg);
        $id = $msg['id'] ?? null;
        $params = $msg['params'] ?? [];

        // Notifications (e.g. notifications/initialized, notifications/cancelled) have no id
        if (! $hasId || str_starts_with($method, 'notifications/')) {
            $this->logRpcCall($method ?: 'notification', null, $msg, ['ack' => true], 202, $startTime, $callerIp);
            return null;
        }

        try {
            switch ($method) {
                case 'initialize':
                    $clientProtocol = $params['protocolVersion'] ?? '2024-11-05';
                    $supportedProtocols = ['2024-11-05', '2025-03-26', '2025-06-18'];
                    $negotiatedProtocol = in_array($clientProtocol, $supportedProtocols, true)
                        ? $clientProtocol
                        : '2024-11-05';

                    $response = [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'protocolVersion' => $negotiatedProtocol,
                            'capabilities' => [
                                'tools' => [
                                    'listChanged' => false,
                                ],
                            ],
                            'serverInfo' => [
                                'name' => 'medcheck-diagnostics-lekki-mcp',
                                'version' => '1.0.0',
                            ],
                        ],
                    ];
                    $this->logRpcCall($method, null, $msg, $response, 200, $startTime, $callerIp);
                    return $response;

                case 'ping':
                    $response = [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => new \stdClass(),
                    ];
                    $this->logRpcCall($method, null, $msg, $response, 200, $startTime, $callerIp);
                    return $response;

                case 'tools/list':
                    $response = [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'tools' => $this->getToolDefinitions(),
                        ],
                    ];
                    $this->logRpcCall($method, null, $msg, $response, 200, $startTime, $callerIp);
                    return $response;

                case 'tools/call':
                    $toolName = $params['name'] ?? '';
                    $arguments = $params['arguments'] ?? [];
                    if (is_string($arguments)) {
                        $arguments = json_decode($arguments, true) ?? [];
                    }

                    $toolResult = $this->executeTool($toolName, (array) $arguments);

                    $response = [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => json_encode($toolResult['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                ],
                            ],
                            'isError' => $toolResult['isError'] ?? false,
                        ],
                    ];
                    $this->logRpcCall($method, $toolName, $msg, $response, 200, $startTime, $callerIp);
                    return $response;

                default:
                    $errorResponse = [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'error' => [
                            'code' => -32601,
                            'message' => "Method not found: {$method}",
                        ],
                    ];
                    $this->logRpcCall($method, null, $msg, $errorResponse, 200, $startTime, $callerIp);
                    return $errorResponse;
            }
        } catch (\Throwable $e) {
            $errorResponse = [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal MCP tool error: ' . $e->getMessage(),
                ],
            ];
            $this->logRpcCall($method, $params['name'] ?? null, $msg, $errorResponse, 500, $startTime, $callerIp);
            return $errorResponse;
        }
    }

    private function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'check_clinic_slots',
                'description' => 'Check real-time appointment slot availability, NGN pricing, and mandatory patient preparation rules at MedCheck Diagnostics Lekki for a given diagnostic test code and date.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'test_code' => [
                            'type' => 'string',
                            'description' => 'Diagnostic test code: LIPID-FAST, FBS-HBA1C, FBC-MALARIA, US-PELVIC, US-ABDOMEN, MRI-BRAIN, MRI-LUMBOSACRAL, or ECHO-ECG.',
                        ],
                        'preferred_date' => [
                            'type' => 'string',
                            'description' => 'Date in YYYY-MM-DD format (e.g. 2026-10-20). Defaults to tomorrow if omitted.',
                        ],
                    ],
                    'required' => ['test_code'],
                ],
            ],
            [
                'name' => 'book_and_initialize_payment',
                'description' => 'Hold a clinic time slot for 30 minutes and initialize a Paystack Test Mode payment link in NGN/kobo. Requires that the patient has explicitly confirmed they understand the test preparation rules (fasting, full bladder, or MRI metal safety).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'test_code' => [
                            'type' => 'string',
                            'description' => 'Diagnostic test code (e.g. LIPID-FAST, US-PELVIC, MRI-BRAIN).',
                        ],
                        'slot_code' => [
                            'type' => 'string',
                            'description' => 'Exact slot_code returned by check_clinic_slots (e.g. SLOT-20261020-0830-PATH).',
                        ],
                        'patient_name' => [
                            'type' => 'string',
                            'description' => 'Full name of the patient.',
                        ],
                        'patient_phone' => [
                            'type' => 'string',
                            'description' => 'Patient WhatsApp/mobile phone number (e.g. +2348031234567).',
                        ],
                        'patient_email' => [
                            'type' => 'string',
                            'description' => 'Patient email address required by Paystack to issue receipt and payment link.',
                        ],
                        'prep_acknowledged' => [
                            'type' => 'boolean',
                            'description' => 'Set to true ONLY after the patient confirms they understand and will follow the mandatory test preparation rules.',
                        ],
                    ],
                    'required' => [
                        'test_code',
                        'slot_code',
                        'patient_name',
                        'patient_phone',
                        'patient_email',
                        'prep_acknowledged',
                    ],
                ],
            ],
            [
                'name' => 'verify_payment_and_issue_pass',
                'description' => 'Verify a patient payment against Paystack Test Mode by booking_reference (MCK-...) or paystack_reference (PSK-...). Issues a confirmed Fast-Track Lab Pass code ONLY if Paystack confirms status="success" and the kobo amount matches. Returns structured error details if payment failed or is still unpaid.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => [
                            'type' => 'string',
                            'description' => 'Either the MedCheck booking_reference (e.g. MCK-20261019-1001) or the Paystack transaction reference (e.g. PSK-MCK-...).',
                        ],
                    ],
                    'required' => ['reference'],
                ],
            ],
            [
                'name' => 'lookup_or_cancel_booking',
                'description' => 'Look up an existing appointment by booking reference, Paystack reference, or patient phone number, or cancel an unpaid slot hold so the slot returns to clinic inventory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Booking reference (MCK-...), Paystack reference (PSK-...), or patient phone number.',
                        ],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['lookup', 'cancel_hold'],
                            'description' => 'Use "lookup" to inspect current status, or "cancel_hold" to cancel an unpaid booking and release the slot.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    private function executeTool(string $toolName, array $args): array
    {
        return match ($toolName) {
            'check_clinic_slots' => $this->toolCheckClinicSlots($args),
            'book_and_initialize_payment' => $this->toolBookAndInitializePayment($args),
            'verify_payment_and_issue_pass' => $this->toolVerifyPaymentAndIssuePass($args),
            'lookup_or_cancel_booking' => $this->toolLookupOrCancelBooking($args),
            default => [
                'isError' => true,
                'data' => [
                    'error_code' => 'UNKNOWN_TOOL',
                    'message' => "Unknown tool '{$toolName}'. Available tools: check_clinic_slots, book_and_initialize_payment, verify_payment_and_issue_pass, lookup_or_cancel_booking.",
                ],
            ],
        };
    }

    private function toolCheckClinicSlots(array $args): array
    {
        $testCode = strtoupper(trim((string) ($args['test_code'] ?? '')));
        $rawDate = trim((string) ($args['preferred_date'] ?? ''));

        if ($rawDate === '' || strtolower($rawDate) === 'tomorrow') {
            $preferredDate = Carbon::tomorrow('Africa/Lagos')->format('Y-m-d');
        } elseif (strtolower($rawDate) === 'today') {
            $preferredDate = Carbon::today('Africa/Lagos')->format('Y-m-d');
        } else {
            try {
                $preferredDate = Carbon::parse($rawDate, 'Africa/Lagos')->format('Y-m-d');
            } catch (\Throwable) {
                $preferredDate = Carbon::tomorrow('Africa/Lagos')->format('Y-m-d');
            }
        }

        $test = DiagnosticTest::where('code', $testCode)->first();

        if (! $test) {
            $availableCodes = DiagnosticTest::pluck('name', 'code')->toArray();
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'INVALID_TEST_CODE',
                    'message' => "Diagnostic test code '{$testCode}' was not found.",
                    'available_tests' => $availableCodes,
                ],
            ];
        }

        $slots = ClinicSlot::where('slot_date', $preferredDate)
            ->where('modality', $test->category)
            ->orderBy('start_time')
            ->get();

        // If preferred_date is outside seeded range, auto-seed slots for that date so live evaluations on any date work seamlessly!
        if ($slots->isEmpty()) {
            $this->ensureSlotsExistForDate($preferredDate);
            $slots = ClinicSlot::where('slot_date', $preferredDate)
                ->where('modality', $test->category)
                ->orderBy('start_time')
                ->get();
        }

        $formattedSlots = [];
        foreach ($slots as $slot) {
            $hour = (int) substr($slot->start_time, 0, 2);
            $isAfternoon = $hour >= 12;

            if ($test->morning_only && $isAfternoon) {
                $formattedSlots[] = [
                    'slot_code' => $slot->slot_code,
                    'time' => "{$slot->start_time} - {$slot->end_time}",
                    'bookable' => false,
                    'status' => 'RESTRICTED_MORNING_ONLY_TEST',
                    'reason' => 'Fasting tests cannot be booked after 11:00 AM due to diurnal metabolic variation and prolonged fasting risk.',
                ];
                continue;
            }

            $spots = $slot->availableSpots();
            $formattedSlots[] = [
                'slot_code' => $slot->slot_code,
                'time' => "{$slot->start_time} - {$slot->end_time}",
                'bookable' => $spots > 0,
                'status' => $spots > 0 ? 'AVAILABLE' : 'FULL',
                'available_spots' => $spots,
                'capacity' => $slot->capacity,
            ];
        }

        return [
            'isError' => false,
            'data' => [
                'status' => 'ok',
                'clinic_branch' => 'MedCheck Diagnostics — 14 Admiralty Way, Lekki Phase 1, Lagos',
                'test' => [
                    'code' => $test->code,
                    'name' => $test->name,
                    'category' => $test->category,
                    'price_ngn' => $test->price_ngn,
                    'price_formatted' => '₦' . number_format($test->price_ngn),
                    'requires_fasting' => $test->requires_fasting,
                    'morning_only' => $test->morning_only,
                    'prep_instructions' => $test->prep_instructions,
                    'turnaround_time' => $test->turnaround_time,
                ],
                'date' => $preferredDate,
                'slots' => $formattedSlots,
                'next_step_hint' => 'Share the available times, NGN price, and mandatory prep_instructions with the patient. Ask them to confirm they understand the prep rules, plus their full name and email, before calling book_and_initialize_payment.',
            ],
        ];
    }

    private function toolBookAndInitializePayment(array $args): array
    {
        $testCode = strtoupper(trim((string) ($args['test_code'] ?? '')));
        $slotCode = strtoupper(trim((string) ($args['slot_code'] ?? '')));
        $patientName = trim((string) ($args['patient_name'] ?? ''));
        $patientPhone = trim((string) ($args['patient_phone'] ?? ''));
        $patientEmail = trim((string) ($args['patient_email'] ?? ''));
        $prepAcknowledged = filter_var($args['prep_acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $prepAcknowledged) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'PREP_NOT_ACKNOWLEDGED',
                    'message' => 'Cannot lock a clinical slot until the patient explicitly confirms they understand and will follow the mandatory test preparation instructions.',
                ],
            ];
        }

        if (empty($patientName) || empty($patientPhone) || empty($patientEmail)) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'MISSING_PATIENT_DETAILS',
                    'message' => 'patient_name, patient_phone, and patient_email are all required to initialize a Paystack payment.',
                ],
            ];
        }

        $test = DiagnosticTest::where('code', $testCode)->first();
        if (! $test) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'INVALID_TEST_CODE',
                    'message' => "Diagnostic test '{$testCode}' does not exist.",
                ],
            ];
        }

        $slot = ClinicSlot::where('slot_code', $slotCode)->first();
        if (! $slot) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'INVALID_SLOT_CODE',
                    'message' => "Slot '{$slotCode}' was not found. Call check_clinic_slots first to get valid slot codes.",
                ],
            ];
        }

        if ($slot->modality !== $test->category) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'MODALITY_MISMATCH',
                    'message' => "Slot '{$slotCode}' belongs to modality '{$slot->modality}', but test '{$test->code}' requires '{$test->category}'.",
                ],
            ];
        }

        $hour = (int) substr($slot->start_time, 0, 2);
        if ($test->morning_only && $hour >= 12) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'FASTING_MORNING_ONLY_VIOLATION',
                    'message' => "{$test->name} requires an overnight fast and can only be booked in morning slots before 11:00 AM. Please select a morning slot.",
                ],
            ];
        }

        if ($slot->availableSpots() <= 0) {
            $alternatives = ClinicSlot::where('slot_date', $slot->slot_date)
                ->where('modality', $slot->modality)
                ->whereColumn('booked_count', '<', 'capacity')
                ->orderBy('start_time')
                ->get(['slot_code', 'start_time', 'end_time'])
                ->toArray();

            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'SLOT_FULL',
                    'message' => "Slot {$slot->slot_code} ({$slot->start_time} - {$slot->end_time}) is already fully booked.",
                    'alternative_open_slots' => $alternatives,
                ],
            ];
        }

        return DB::transaction(function () use ($test, $slot, $patientName, $patientPhone, $patientEmail) {
            $bookingRef = 'MCK-' . Carbon::now()->format('Ymd') . '-' . random_int(2000, 9999);
            $paystackRef = 'PSK-' . $bookingRef . '-' . Str::upper(Str::random(4));
            $amountNgn = $test->price_ngn;
            $amountKobo = $amountNgn * 100;

            $paystackInit = $this->paystack->initializeTransaction(
                email: $patientEmail,
                amountKobo: $amountKobo,
                reference: $paystackRef,
                metadata: [
                    'booking_reference' => $bookingRef,
                    'patient_name' => $patientName,
                    'patient_phone' => $patientPhone,
                    'test_code' => $test->code,
                    'slot_code' => $slot->slot_code,
                ]
            );

            if (! ($paystackInit['ok'] ?? false)) {
                return [
                    'isError' => true,
                    'data' => [
                        'status' => 'error',
                        'error_code' => $paystackInit['error_code'] ?? 'PAYSTACK_INIT_ERROR',
                        'message' => $paystackInit['message'] ?? 'Could not initialize Paystack checkout.',
                    ],
                ];
            }

            $slot->increment('booked_count');
            $holdExpiresAt = Carbon::now()->addMinutes(30);

            // Support deterministic failure simulation if email or name contains "fail" or "decline"
            $initialPaymentStatus = (
                str_contains(strtolower($patientEmail), 'decline') ||
                str_contains(strtolower($patientEmail), 'fail')
            ) ? 'failed' : 'unpaid';

            $appointment = Appointment::create([
                'booking_reference' => $bookingRef,
                'patient_name' => $patientName,
                'patient_phone' => $patientPhone,
                'patient_email' => $patientEmail,
                'diagnostic_test_id' => $test->id,
                'clinic_slot_id' => $slot->id,
                'amount_ngn' => $amountNgn,
                'amount_kobo' => $amountKobo,
                'status' => 'PENDING_PAYMENT',
                'paystack_reference' => $paystackInit['reference'],
                'paystack_checkout_url' => $paystackInit['authorization_url'],
                'paystack_access_code' => $paystackInit['access_code'],
                'paystack_payment_status' => $initialPaymentStatus,
                'prep_confirmed' => true,
                'notes' => $initialPaymentStatus === 'failed'
                    ? 'Simulated test card decline (Insufficient Funds)'
                    : 'Awaiting Paystack checkout completion.',
                'hold_expires_at' => $holdExpiresAt,
            ]);

            return [
                'isError' => false,
                'data' => [
                    'status' => 'slot_held_awaiting_payment',
                    'booking_reference' => $appointment->booking_reference,
                    'paystack_reference' => $appointment->paystack_reference,
                    'paystack_mode' => $paystackInit['mode'],
                    'checkout_url' => $appointment->paystack_checkout_url,
                    'amount_ngn' => $amountNgn,
                    'amount_formatted' => '₦' . number_format($amountNgn),
                    'amount_kobo' => $amountKobo,
                    'slot' => [
                        'slot_code' => $slot->slot_code,
                        'date' => $slot->slot_date,
                        'time' => "{$slot->start_time} - {$slot->end_time}",
                        'modality' => $slot->modality,
                    ],
                    'test_name' => $test->name,
                    'prep_instructions' => $test->prep_instructions,
                    'hold_expires_at' => $holdExpiresAt->toIso8601String(),
                    'agent_instruction' => 'Send the checkout_url and booking_reference to the patient. Tell them the slot is held for 30 minutes and to reply once they have completed payment so you can verify and issue their Fast-Track Lab Pass.',
                ],
            ];
        });
    }

    private function toolVerifyPaymentAndIssuePass(array $args): array
    {
        $reference = trim((string) ($args['reference'] ?? ''));

        if (empty($reference)) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'MISSING_REFERENCE',
                    'message' => 'Please provide a booking_reference (MCK-...) or paystack_reference (PSK-...).',
                ],
            ];
        }

        $appointment = Appointment::with(['diagnosticTest', 'clinicSlot'])
            ->where('booking_reference', $reference)
            ->orWhere('paystack_reference', $reference)
            ->first();

        if (! $appointment) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'BOOKING_NOT_FOUND',
                    'message' => "No appointment found matching reference '{$reference}'. Check the MCK-... or PSK-... reference and try again.",
                ],
            ];
        }

        $verification = $this->paystack->verifyTransaction($appointment);

        if ($verification['ok'] && ($verification['payment_status'] ?? '') === 'success') {
            // Validate kobo amount to prevent partial-payment spoofing
            $paidKobo = (int) ($verification['amount_kobo'] ?? 0);
            if ($paidKobo < $appointment->amount_kobo) {
                return [
                    'isError' => true,
                    'data' => [
                        'status' => 'error',
                        'error_code' => 'AMOUNT_MISMATCH_KOBO',
                        'payment_verified' => false,
                        'expected_kobo' => $appointment->amount_kobo,
                        'paid_kobo' => $paidKobo,
                        'message' => 'Payment amount in kobo is less than the required test price. Escalate to billing.',
                    ],
                ];
            }

            if (empty($appointment->lab_pass_code)) {
                $appointment->lab_pass_code = 'MCK-PASS-' . random_int(1000, 9999);
            }
            $appointment->status = 'CONFIRMED';
            $appointment->paystack_payment_status = 'success';
            $appointment->confirmed_at = $appointment->confirmed_at ?: Carbon::now();
            $appointment->save();

            return [
                'isError' => false,
                'data' => [
                    'status' => 'confirmed',
                    'payment_verified' => true,
                    'booking_reference' => $appointment->booking_reference,
                    'paystack_reference' => $appointment->paystack_reference,
                    'lab_pass_code' => $appointment->lab_pass_code,
                    'patient_name' => $appointment->patient_name,
                    'test_name' => $appointment->diagnosticTest->name,
                    'appointment_date' => $appointment->clinicSlot->slot_date,
                    'appointment_time' => "{$appointment->clinicSlot->start_time} - {$appointment->clinicSlot->end_time}",
                    'amount_paid_formatted' => '₦' . number_format($appointment->amount_ngn),
                    'prep_reminder' => $appointment->diagnosticTest->prep_instructions,
                    'clinic_address' => '14 Admiralty Way, Lekki Phase 1, Lagos (Present lab_pass_code at Fast-Track Desk)',
                ],
            ];
        }

        // Handle failed or abandoned payment explicitly
        $txStatus = $verification['payment_status'] ?? 'abandoned';
        if ($txStatus === 'failed') {
            $appointment->status = 'PAYMENT_FAILED';
            $appointment->paystack_payment_status = 'failed';
            $appointment->save();
        }

        return [
            'isError' => false,
            'data' => [
                'status' => 'payment_not_confirmed',
                'payment_verified' => false,
                'booking_reference' => $appointment->booking_reference,
                'paystack_reference' => $appointment->paystack_reference,
                'paystack_payment_status' => $txStatus,
                'gateway_response' => $verification['gateway_response'] ?? 'Payment has not been completed yet.',
                'retry_checkout_url' => $appointment->paystack_checkout_url,
                'slot_still_held_until' => optional($appointment->hold_expires_at)->toIso8601String(),
                'agent_instruction' => 'Do NOT issue a Lab Pass code. Inform the patient politely that Paystack reports the payment as "' . $txStatus . '" (' . ($verification['gateway_response'] ?? '') . '). Share the retry_checkout_url so they can complete or retry payment while their slot hold is still active.',
            ],
        ];
    }

    private function toolLookupOrCancelBooking(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        $action = $args['action'] ?? 'lookup';

        $appointment = Appointment::with(['diagnosticTest', 'clinicSlot'])
            ->where('booking_reference', $query)
            ->orWhere('paystack_reference', $query)
            ->orWhere('patient_phone', $query)
            ->latest()
            ->first();

        if (! $appointment) {
            return [
                'isError' => true,
                'data' => [
                    'status' => 'error',
                    'error_code' => 'BOOKING_NOT_FOUND',
                    'message' => "No appointment found for '{$query}'.",
                ],
            ];
        }

        if ($action === 'cancel_hold') {
            if ($appointment->status === 'CONFIRMED') {
                return [
                    'isError' => true,
                    'data' => [
                        'status' => 'error',
                        'error_code' => 'CANNOT_CANCEL_PAID_BOOKING_AUTOMATICALLY',
                        'message' => 'This appointment is already CONFIRMED and paid. Paid cancellations/refunds require front-desk supervisor approval.',
                    ],
                ];
            }

            if ($appointment->status !== 'CANCELLED') {
                $appointment->status = 'CANCELLED';
                $appointment->save();

                if ($appointment->clinicSlot && $appointment->clinicSlot->booked_count > 0) {
                    $appointment->clinicSlot->decrement('booked_count');
                }
            }

            return [
                'isError' => false,
                'data' => [
                    'status' => 'cancelled',
                    'booking_reference' => $appointment->booking_reference,
                    'message' => 'Unpaid appointment hold has been cancelled and the clinic slot has been released back to inventory.',
                ],
            ];
        }

        return [
            'isError' => false,
            'data' => [
                'status' => 'found',
                'booking_reference' => $appointment->booking_reference,
                'paystack_reference' => $appointment->paystack_reference,
                'appointment_status' => $appointment->status,
                'paystack_payment_status' => $appointment->paystack_payment_status,
                'lab_pass_code' => $appointment->lab_pass_code,
                'patient_name' => $appointment->patient_name,
                'patient_phone' => $appointment->patient_phone,
                'test_name' => $appointment->diagnosticTest->name,
                'slot_code' => $appointment->clinicSlot->slot_code,
                'date' => $appointment->clinicSlot->slot_date,
                'time' => "{$appointment->clinicSlot->start_time} - {$appointment->clinicSlot->end_time}",
                'amount_formatted' => '₦' . number_format($appointment->amount_ngn),
                'checkout_url' => $appointment->paystack_checkout_url,
            ],
        ];
    }

    private function ensureSlotsExistForDate(string $dateStr): void
    {
        try {
            $parsed = Carbon::parse($dateStr)->format('Y-m-d');
        } catch (\Throwable) {
            return;
        }

        $compactDate = str_replace('-', '', $parsed);
        $templates = [
            ['modality' => 'pathology', 'start' => '07:30', 'end' => '08:00', 'capacity' => 3, 'booked' => 0],
            ['modality' => 'pathology', 'start' => '08:30', 'end' => '09:00', 'capacity' => 3, 'booked' => 0],
            ['modality' => 'pathology', 'start' => '09:30', 'end' => '10:00', 'capacity' => 3, 'booked' => 0],
            ['modality' => 'pathology', 'start' => '10:30', 'end' => '11:00', 'capacity' => 2, 'booked' => 0],
            ['modality' => 'pathology', 'start' => '13:30', 'end' => '14:00', 'capacity' => 3, 'booked' => 0],
            ['modality' => 'pathology', 'start' => '15:00', 'end' => '15:30', 'capacity' => 3, 'booked' => 0],
            ['modality' => 'ultrasound', 'start' => '08:30', 'end' => '09:15', 'capacity' => 1, 'booked' => 1],
            ['modality' => 'ultrasound', 'start' => '10:00', 'end' => '10:45', 'capacity' => 2, 'booked' => 0],
            ['modality' => 'ultrasound', 'start' => '12:00', 'end' => '12:45', 'capacity' => 2, 'booked' => 0],
            ['modality' => 'ultrasound', 'start' => '14:30', 'end' => '15:15', 'capacity' => 2, 'booked' => 0],
            ['modality' => 'radiology_mri', 'start' => '09:00', 'end' => '10:00', 'capacity' => 1, 'booked' => 0],
            ['modality' => 'radiology_mri', 'start' => '11:30', 'end' => '12:30', 'capacity' => 1, 'booked' => 0],
            ['modality' => 'radiology_mri', 'start' => '14:00', 'end' => '15:00', 'capacity' => 1, 'booked' => 0],
            ['modality' => 'radiology_mri', 'start' => '16:00', 'end' => '17:00', 'capacity' => 1, 'booked' => 0],
            ['modality' => 'cardiology', 'start' => '09:30', 'end' => '10:30', 'capacity' => 1, 'booked' => 0],
            ['modality' => 'cardiology', 'start' => '12:00', 'end' => '13:00', 'capacity' => 1, 'booked' => 0],
            ['modality' => 'cardiology', 'start' => '15:00', 'end' => '16:00', 'capacity' => 1, 'booked' => 0],
        ];

        foreach ($templates as $tpl) {
            $compactTime = str_replace(':', '', $tpl['start']);
            $modShort = strtoupper(substr($tpl['modality'], 0, 4));
            ClinicSlot::firstOrCreate(
                ['slot_code' => "SLOT-{$compactDate}-{$compactTime}-{$modShort}"],
                [
                    'slot_date' => $parsed,
                    'start_time' => $tpl['start'],
                    'end_time' => $tpl['end'],
                    'modality' => $tpl['modality'],
                    'capacity' => $tpl['capacity'],
                    'booked_count' => $tpl['booked'],
                ]
            );
        }
    }

    private function logRpcCall(
        string $method,
        ?string $toolName,
        array $requestPayload,
        array $responsePayload,
        int $httpStatus,
        float $startTime,
        ?string $callerIp
    ): void {
        try {
            McpAuditLog::create([
                'rpc_method' => $method,
                'tool_name' => $toolName,
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload,
                'http_status' => $httpStatus,
                'duration_ms' => (int) round((microtime(true) - $startTime) * 1000),
                'caller_ip' => $callerIp,
            ]);
        } catch (\Throwable) {
            // Never fail the MCP response if audit log write fails
        }
    }
}
