<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackService
{
    private string $baseUrl;
    private ?string $secretKey;
    private ?string $currency;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.paystack.base_url', 'https://api.paystack.co'), '/');
        $this->secretKey = config('services.paystack.secret_key');
        $this->currency = config('services.paystack.currency'); // Optional; when null, Paystack uses merchant's default currency (e.g. GHS/NGN)
    }

    public function hasLiveTestKey(): bool
    {
        return ! empty($this->secretKey) && str_starts_with($this->secretKey, 'sk_test_');
    }

    /**
     * Initialize a Paystack transaction in smallest currency unit (kobo / pesewas = amount * 100).
     * Calls real Paystack API when sk_test_... is configured; falls back to hosted interactive test checkout URL otherwise.
     */
    public function initializeTransaction(
        string $email,
        int $amountKobo,
        string $reference,
        array $metadata = []
    ): array {
        if ($this->hasLiveTestKey()) {
            try {
                $payload = [
                    'email' => $email,
                    'amount' => $amountKobo,
                    'reference' => $reference,
                    'callback_url' => url("/paystack/callback?reference={$reference}"),
                    'metadata' => $metadata,
                ];

                if (! empty($this->currency)) {
                    $payload['currency'] = $this->currency;
                }

                $response = Http::withToken($this->secretKey)
                    ->timeout(10)
                    ->acceptJson()
                    ->post("{$this->baseUrl}/transaction/initialize", $payload);

                $body = $response->json();

                // Fallback: if merchant account is domiciled in another currency (e.g. GHS vs NGN) and rejects explicit currency
                if (
                    ! $response->successful() &&
                    isset($payload['currency']) &&
                    str_contains(strtolower($body['message'] ?? ''), 'currency not supported')
                ) {
                    unset($payload['currency']);
                    $response = Http::withToken($this->secretKey)
                        ->timeout(10)
                        ->acceptJson()
                        ->post("{$this->baseUrl}/transaction/initialize", $payload);
                    $body = $response->json();
                }

                if ($response->successful() && ($body['status'] ?? false) === true) {
                    return [
                        'ok' => true,
                        'mode' => 'paystack_api_test',
                        'authorization_url' => $body['data']['authorization_url'],
                        'access_code' => $body['data']['access_code'],
                        'reference' => $body['data']['reference'],
                    ];
                }

                Log::warning('Paystack initialize returned non-success', [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return [
                    'ok' => false,
                    'mode' => 'paystack_api_test',
                    'error_code' => 'PAYSTACK_INIT_REJECTED',
                    'message' => $body['message'] ?? 'Paystack rejected transaction initialization.',
                ];
            } catch (\Throwable $e) {
                Log::error('Paystack initialize exception', ['error' => $e->getMessage()]);

                return [
                    'ok' => false,
                    'mode' => 'paystack_api_test',
                    'error_code' => 'PAYSTACK_NETWORK_ERROR',
                    'message' => 'Could not reach Paystack API: ' . $e->getMessage(),
                ];
            }
        }

        // Sandbox interactive checkout page hosted by this Laravel app when sk_test_ is not yet provided
        $accessCode = 'acc_sim_' . Str::lower(Str::random(10));

        return [
            'ok' => true,
            'mode' => 'paystack_interactive_sandbox',
            'authorization_url' => url("/paystack/checkout/{$reference}"),
            'access_code' => $accessCode,
            'reference' => $reference,
        ];
    }

    /**
     * Verify a transaction by reference against Paystack Test API (or interactive sandbox state).
     */
    public function verifyTransaction(Appointment $appointment): array
    {
        $reference = $appointment->paystack_reference;

        // If the appointment was already explicitly simulated as paid or failed via the interactive dashboard or seeded demo
        if ($appointment->paystack_payment_status === 'success') {
            return [
                'ok' => true,
                'payment_status' => 'success',
                'amount_kobo' => $appointment->amount_kobo,
                'currency' => $this->currency ?: 'NGN',
                'gateway_response' => 'Approved (Test Mode)',
                'channel' => 'card',
                'reference' => $reference,
            ];
        }

        if ($appointment->paystack_payment_status === 'failed') {
            return [
                'ok' => false,
                'payment_status' => 'failed',
                'amount_kobo' => $appointment->amount_kobo,
                'currency' => $this->currency ?: 'NGN',
                'gateway_response' => $appointment->notes ?: 'Declined by financial institution (Insufficient Funds)',
                'reference' => $reference,
            ];
        }

        // Call live Paystack Test Mode API if sk_test_ key is configured
        if ($this->hasLiveTestKey() && ! empty($reference)) {
            try {
                $response = Http::withToken($this->secretKey)
                    ->timeout(10)
                    ->acceptJson()
                    ->get("{$this->baseUrl}/transaction/verify/" . urlencode($reference));

                $body = $response->json();

                if ($response->successful() && ($body['status'] ?? false) === true && isset($body['data'])) {
                    $data = $body['data'];
                    $txStatus = $data['status'] ?? 'abandoned'; // success, failed, abandoned, ongoing
                    $paidSubunits = (int) ($data['amount'] ?? 0);
                    $currency = $data['currency'] ?? 'NGN';
                    $gatewayResponse = $data['gateway_response'] ?? 'No gateway response';

                    return [
                        'ok' => $txStatus === 'success',
                        'payment_status' => $txStatus,
                        'amount_kobo' => $paidSubunits,
                        'currency' => $currency,
                        'gateway_response' => $gatewayResponse,
                        'channel' => $data['channel'] ?? 'unknown',
                        'reference' => $reference,
                    ];
                }

                return [
                    'ok' => false,
                    'payment_status' => 'not_found',
                    'gateway_response' => $body['message'] ?? 'Transaction reference not found on Paystack.',
                    'reference' => $reference,
                ];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'payment_status' => 'network_error',
                    'gateway_response' => 'Error contacting Paystack Verify API: ' . $e->getMessage(),
                    'reference' => $reference,
                ];
            }
        }

        // Default unpaid/abandoned state when customer has not completed payment yet
        return [
            'ok' => false,
            'payment_status' => 'abandoned',
            'amount_kobo' => 0,
            'currency' => 'NGN',
            'gateway_response' => 'Customer has not completed payment on the checkout link yet.',
            'reference' => $reference,
        ];
    }
}
