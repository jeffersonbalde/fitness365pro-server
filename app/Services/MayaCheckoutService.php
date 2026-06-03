<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MayaCheckoutService
{
    /**
     * Simulate Maya Checkout without API keys so paid registration UX can be built end‑to‑end.
     * Set PAYMAYA_MOCK=true outside production, or use local env with empty keys (see mocking()).
     */
    public function mocking(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        if (filter_var(env('PAYMAYA_MOCK', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $pk = trim((string) config('services.paymaya.public_key'));
        $sk = trim((string) config('services.paymaya.secret_key'));

        return app()->environment('local') && $pk === '' && $sk === '';
    }

    public function baseUrl(): string
    {
        $sandbox = filter_var(config('services.paymaya.sandbox'), FILTER_VALIDATE_BOOLEAN);

        return $sandbox
            ? 'https://pg-sandbox.paymaya.com'
            : 'https://pg.paymaya.com';
    }

    public function configured(): bool
    {
        return $this->mocking()
            || (trim((string) config('services.paymaya.public_key')) !== ''
                && trim((string) config('services.paymaya.secret_key')) !== '');
    }

    /**
     * @param  array<string, mixed>  $buyer Maya "Basic buyer": firstName, lastName, optional contact: { phone, email }
     * @param-out ?string $failureHint Human-readable reason when return is null (for API responses / logs)
     * @return array<string, mixed>|null
     */
    public function createCheckout(
        string $amount,
        string $currency,
        array $buyer,
        string $redirectSuccess,
        string $redirectFailure,
        string $redirectCancel,
        string $requestReferenceNumber,
        ?string &$failureHint = null
    ): ?array {
        $failureHint = null;

        if ($this->mocking()) {
            $checkoutId = 'mock-' . Str::lower((string) Str::uuid());

            $sep = str_contains($redirectSuccess, '?') ? '&' : '?';
            $redirectUrl = $redirectSuccess.$sep.http_build_query(['checkoutId' => $checkoutId]);
            Log::info('PayMaya mock checkout created', ['checkout_id' => $checkoutId, 'amount' => $amount]);

            return [
                'checkoutId' => $checkoutId,
                'id' => $checkoutId,
                'redirectUrl' => $redirectUrl,
            ];
        }

        $publicKey = trim((string) config('services.paymaya.public_key'));
        if ($publicKey === '') {
            $failureHint = 'Payment gateway public key is missing.';

            return null;
        }

        $amountNum = round((float) $amount, 2);
        if ($amountNum < 0.01) {
            $failureHint = 'Amount must be at least 0.01.';

            return null;
        }

        $payload = [
            'totalAmount' => [
                'value' => $amountNum,
                'currency' => $currency ?: 'PHP',
            ],
            'redirectUrl' => [
                'success' => $redirectSuccess,
                'failure' => $redirectFailure,
                'cancel' => $redirectCancel,
            ],
            'requestReferenceNumber' => $requestReferenceNumber,
            'metadata' => [
                'Fitness365Pro' => 'event_registration',
            ],
        ];

        if ($buyer !== []) {
            $contactBlock = [];
            if (isset($buyer['contact']) && is_array($buyer['contact'])) {
                $contactBlock = array_filter([
                    'phone' => trim((string) ($buyer['contact']['phone'] ?? '')) ?: null,
                    'email' => trim((string) ($buyer['contact']['email'] ?? '')) ?: null,
                ], static fn ($v) => $v !== null && $v !== '');
            }

            $buyerBlock = array_filter([
                'firstName' => trim((string) ($buyer['firstName'] ?? '')) ?: null,
                'lastName' => trim((string) ($buyer['lastName'] ?? '')) ?: null,
                'contact' => $contactBlock !== [] ? $contactBlock : null,
            ], static fn ($v) => $v !== null && $v !== '' && $v !== []);

            if ($buyerBlock !== []) {
                $payload['buyer'] = $buyerBlock;
            }
        }

        try {
            $response = Http::timeout(40)
                ->withBasicAuth($publicKey, '')
                ->acceptJson()
                ->asJson()
                ->post($this->baseUrl() . '/checkout/v1/checkouts', $payload);
        } catch (\Throwable $e) {
            Log::error('PayMaya create checkout HTTP error', ['message' => $e->getMessage()]);
            $failureHint = 'Could not reach Maya. Check your connection and try again.';

            return null;
        }

        if (! $response->successful()) {
            $failureHint = $this->summarizePaymayaClientError($response);
            Log::warning('PayMaya create checkout failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    private function summarizePaymayaClientError(Response $response): string
    {
        $decoded = $response->json();
        if (! is_array($decoded)) {
            return 'Gateway returned HTTP '.$response->status().'.';
        }

        $parts = [];
        foreach (['message', 'error', 'errorDescription'] as $key) {
            if (! empty($decoded[$key]) && is_string($decoded[$key])) {
                $parts[] = trim($decoded[$key]);
            }
            if (! empty($decoded[$key]) && is_array($decoded[$key])) {
                foreach ($decoded[$key] as $nested) {
                    if (is_string($nested)) {
                        $parts[] = trim($nested);
                    }
                }
            }
        }
        if (! empty($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $k => $v) {
                if (is_array($v)) {
                    $parts[] = is_string($k)
                        ? $k.': '.implode(', ', array_map('strval', $v))
                        : implode(', ', array_map('strval', $v));
                } elseif (is_string($v) || is_numeric($v)) {
                    $parts[] = is_string($k) ? $k.': '.$v : (string) $v;
                }
            }
        }

        $msg = trim(implode(' ', array_unique(array_filter($parts))));
        if ($msg === '') {
            $msg = 'Gateway returned HTTP '.$response->status().'.';
        }

        return Str::limit($msg, 360, '…');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function retrieveCheckout(string $checkoutId): ?array
    {
        if ($checkoutId !== '' && str_starts_with($checkoutId, 'mock-') && $this->mocking()) {
            Log::info('PayMaya mock checkout retrieved', ['checkout_id' => $checkoutId]);

            return [
                'checkoutId' => $checkoutId,
                'paymentStatus' => 'PAYMENT_SUCCESS',
                'status' => 'SUCCESS',
                'isPaid' => true,
            ];
        }

        $secretKey = trim((string) config('services.paymaya.secret_key'));
        if ($secretKey === '' || $checkoutId === '') {
            return null;
        }

        try {
            $response = Http::timeout(40)
                ->withBasicAuth($secretKey, '')
                ->acceptJson()
                ->get($this->baseUrl() . '/checkout/v1/checkouts/' . rawurlencode($checkoutId));
        } catch (\Throwable $e) {
            Log::error('PayMaya retrieve checkout HTTP error', ['message' => $e->getMessage()]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('PayMaya retrieve checkout failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Retrieve payment details using the merchant request reference number (RRN).
     *
     * @return array<string, mixed>|null
     */
    public function retrievePaymentByRrn(string $requestReferenceNumber): ?array
    {
        $rrn = trim($requestReferenceNumber);
        if ($rrn === '') {
            return null;
        }

        if (str_starts_with($rrn, 'mock-') && $this->mocking()) {
            return [
                'requestReferenceNumber' => $rrn,
                'paymentStatus' => 'PAYMENT_SUCCESS',
                'status' => 'SUCCESS',
                'isPaid' => true,
            ];
        }

        $secretKey = trim((string) config('services.paymaya.secret_key'));
        if ($secretKey === '') {
            return null;
        }

        try {
            $response = Http::timeout(40)
                ->withBasicAuth($secretKey, '')
                ->acceptJson()
                ->get($this->baseUrl().'/payments/v1/payment-rrns/'.rawurlencode($rrn));
        } catch (\Throwable $e) {
            Log::error('PayMaya retrieve payment by RRN HTTP error', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('PayMaya retrieve payment by RRN failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'rrn' => $rrn,
            ]);

            return null;
        }

        return $response->json();
    }

    /**
     * Decide if Maya checkout indicates a successful settlement.
     */
    public static function checkoutIndicatesPaid(?array $payload): bool
    {
        return self::payloadIndicatesPaid($payload);
    }

    /**
     * Decide if a Maya checkout / payment payload indicates successful settlement.
     */
    public static function payloadIndicatesPaid(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        $paymentStatus = $payload['paymentStatus'] ?? ($payload['status'] ?? '');
        $paymentStatus = is_string($paymentStatus) ? strtoupper(trim($paymentStatus)) : '';

        $successTokens = [
            'PAYMENT_SUCCESS',
            'PAYMENT_PAID',
            'SUCCESS',
            'PAID',
            'CAPTURED',
            'AUTHORIZED',
        ];

        foreach ($successTokens as $tok) {
            if ($tok === $paymentStatus) {
                return true;
            }
        }

        if (str_contains($paymentStatus, 'SUCCESS') || str_contains($paymentStatus, 'PAID')) {
            return true;
        }

        /** @phpstan-ignore isset.offset */
        if (isset($payload['isPaid']) && filter_var($payload['isPaid'], FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return self::payloadIndicatesPaid($payload['data']);
        }

        return false;
    }
}
