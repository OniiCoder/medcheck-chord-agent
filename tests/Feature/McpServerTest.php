<?php

namespace Tests\Feature;

use App\Models\Appointment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_mcp_initialize_and_tools_list(): void
    {
        $initResponse = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'chord-mcp-client', 'version' => '1.0.0'],
            ],
        ]);

        $initResponse->assertStatus(200)
            ->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.serverInfo.name', 'medcheck-diagnostics-lekki-mcp');

        // Notification (no id) returns 202 Accepted
        $notifResponse = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
        $notifResponse->assertStatus(202);

        // Tools discovery
        $toolsResponse = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ]);

        $toolsResponse->assertStatus(200);
        $toolNames = collect($toolsResponse->json('result.tools'))->pluck('name')->all();
        $this->assertEquals([
            'check_clinic_slots',
            'book_and_initialize_payment',
            'verify_payment_and_issue_pass',
            'lookup_or_cancel_booking',
        ], $toolNames);
    }

    public function test_check_clinic_slots_enforces_morning_only_for_fasting_lipid_profile(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => [
                'name' => 'check_clinic_slots',
                'arguments' => [
                    'test_code' => 'LIPID-FAST',
                    'preferred_date' => '2026-10-20',
                ],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('result.isError', false);

        $payload = json_decode($response->json('result.content.0.text'), true);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals(28500, $payload['test']['price_ngn']);
        $this->assertTrue($payload['test']['requires_fasting']);

        $afternoonSlot = collect($payload['slots'])->firstWhere('slot_code', 'SLOT-20261020-1330-PATH');
        $this->assertFalse($afternoonSlot['bookable']);
        $this->assertEquals('RESTRICTED_MORNING_ONLY_TEST', $afternoonSlot['status']);
    }

    public function test_booking_requires_prep_acknowledgement_and_rejects_full_slots(): void
    {
        // 1. Rejects when prep_acknowledged is false
        $unconfirmed = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => [
                'name' => 'book_and_initialize_payment',
                'arguments' => [
                    'test_code' => 'US-PELVIC',
                    'slot_code' => 'SLOT-20261020-1000-ULTR',
                    'patient_name' => 'Ngozi Eze',
                    'patient_phone' => '+2348022223333',
                    'patient_email' => 'ngozi@example.com',
                    'prep_acknowledged' => false,
                ],
            ],
        ]);

        $unconfirmed->assertJsonPath('result.isError', true);
        $errData = json_decode($unconfirmed->json('result.content.0.text'), true);
        $this->assertEquals('PREP_NOT_ACKNOWLEDGED', $errData['error_code']);

        // 2. Rejects pre-seeded FULL slot (08:30 ultrasound tomorrow) and returns alternative open slots
        $fullSlotResp = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => [
                'name' => 'book_and_initialize_payment',
                'arguments' => [
                    'test_code' => 'US-PELVIC',
                    'slot_code' => 'SLOT-20261020-0830-ULTR',
                    'patient_name' => 'Ngozi Eze',
                    'patient_phone' => '+2348022223333',
                    'patient_email' => 'ngozi@example.com',
                    'prep_acknowledged' => true,
                ],
            ],
        ]);

        $fullSlotResp->assertJsonPath('result.isError', true);
        $fullData = json_decode($fullSlotResp->json('result.content.0.text'), true);
        $this->assertEquals('SLOT_FULL', $fullData['error_code']);
        $this->assertNotEmpty($fullData['alternative_open_slots']);
    }

    public function test_end_to_end_booking_payment_verification_and_failed_payment_handling(): void
    {
        // 1. Book an open morning slot for LIPID-FAST
        $bookResp = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => [
                'name' => 'book_and_initialize_payment',
                'arguments' => [
                    'test_code' => 'LIPID-FAST',
                    'slot_code' => 'SLOT-20261020-0830-PATH',
                    'patient_name' => 'Tunde Bakare',
                    'patient_phone' => '+2348055556666',
                    'patient_email' => 'tunde.bakare@example.com',
                    'prep_acknowledged' => true,
                ],
            ],
        ]);

        $bookResp->assertJsonPath('result.isError', false);
        $bookData = json_decode($bookResp->json('result.content.0.text'), true);
        $bookingRef = $bookData['booking_reference'];
        $this->assertEquals(2850000, $bookData['amount_kobo']);
        $this->assertNotEmpty($bookData['checkout_url']);

        // 2. Verify BEFORE paying -> returns payment_verified: false (abandoned) and NO lab_pass_code
        $unpaidVerify = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'verify_payment_and_issue_pass',
                'arguments' => ['reference' => $bookingRef],
            ],
        ]);

        $unpaidData = json_decode($unpaidVerify->json('result.content.0.text'), true);
        $this->assertFalse($unpaidData['payment_verified']);
        $this->assertEquals('abandoned', $unpaidData['paystack_payment_status']);
        $this->assertArrayNotHasKey('lab_pass_code', $unpaidData);

        // 3. Verify pre-seeded FAILED payment (MCK-20261019-1002) -> returns payment_verified: false (failed)
        $failedVerify = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'tools/call',
            'params' => [
                'name' => 'verify_payment_and_issue_pass',
                'arguments' => ['reference' => 'MCK-20261019-1002'],
            ],
        ]);

        $failedData = json_decode($failedVerify->json('result.content.0.text'), true);
        $this->assertFalse($failedData['payment_verified']);
        $this->assertEquals('failed', $failedData['paystack_payment_status']);

        // 4. Simulate Paystack payment success on Tunde's booking and verify again -> issues MCK-PASS-XXXX
        Appointment::where('booking_reference', $bookingRef)->update(['paystack_payment_status' => 'success']);

        $paidVerify = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'tools/call',
            'params' => [
                'name' => 'verify_payment_and_issue_pass',
                'arguments' => ['reference' => $bookingRef],
            ],
        ]);

        $paidData = json_decode($paidVerify->json('result.content.0.text'), true);
        $this->assertTrue($paidData['payment_verified']);
        $this->assertEquals('confirmed', $paidData['status']);
        $this->assertStringStartsWith('MCK-PASS-', $paidData['lab_pass_code']);
    }
}
