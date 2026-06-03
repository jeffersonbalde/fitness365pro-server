<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EventRegistrationPaymentService
{
    public function __construct(
        private readonly MayaCheckoutService $maya,
    ) {}

    public function findPendingByMayaRefs(?string $checkoutId, ?string $requestReferenceNumber): ?ClientAdminEventRegistration
    {
        $checkoutId = trim((string) $checkoutId);
        $rrn = trim((string) $requestReferenceNumber);

        if ($checkoutId === '' && $rrn === '') {
            return null;
        }

        $query = ClientAdminEventRegistration::query()
            ->where('registration_status', 'pending_payment')
            ->where('payment_status', 'pending_checkout');

        if ($checkoutId !== '' && $rrn !== '') {
            return $query->where(function ($q) use ($checkoutId, $rrn) {
                $q->where('paymaya_checkout_id', $checkoutId)
                    ->orWhere('paymaya_rrn', $rrn);
            })->first();
        }

        if ($checkoutId !== '') {
            return $query->where('paymaya_checkout_id', $checkoutId)->first();
        }

        return $query->where('paymaya_rrn', $rrn)->first();
    }

    /**
     * Poll Maya for the latest checkout / payment status and confirm registration when paid.
     *
     * @return array{paid: bool, gateway_status: string, message: string}
     */
    public function syncRegistrationPayment(AdminEvent $event, ClientAdminEventRegistration $reg, string $clientId): array
    {
        if ($reg->registration_status === 'confirmed' && in_array($reg->payment_status, ['paid', 'free'], true)) {
            return [
                'paid' => true,
                'gateway_status' => (string) ($reg->paymaya_payment_status_snapshot ?? 'CONFIRMED'),
                'message' => 'Registration already confirmed.',
            ];
        }

        if ($reg->registration_status !== 'pending_payment' || $reg->payment_status !== 'pending_checkout') {
            return [
                'paid' => false,
                'gateway_status' => '',
                'message' => 'This registration is not awaiting online payment.',
            ];
        }

        if (! $this->maya->configured()) {
            return [
                'paid' => false,
                'gateway_status' => '',
                'message' => 'Payment gateway is not configured.',
            ];
        }

        $remote = null;
        $checkoutId = trim((string) ($reg->paymaya_checkout_id ?? ''));
        $rrn = trim((string) ($reg->paymaya_rrn ?? ''));

        if ($checkoutId !== '') {
            $remote = $this->maya->retrieveCheckout($checkoutId);
        }

        if ($remote === null && $rrn !== '') {
            $remote = $this->maya->retrievePaymentByRrn($rrn);
        }

        if ($remote === null) {
            return [
                'paid' => false,
                'gateway_status' => (string) ($reg->paymaya_payment_status_snapshot ?? ''),
                'message' => 'Unable to verify payment with Maya yet. Try again shortly.',
            ];
        }

        $statusRaw = $this->extractGatewayStatus($remote);
        $reg->paymaya_payment_status_snapshot = Str::limit($statusRaw ?: 'UNKNOWN', 64, '');
        $reg->save();

        if (! MayaCheckoutService::payloadIndicatesPaid($remote)) {
            return [
                'paid' => false,
                'gateway_status' => $statusRaw,
                'message' => 'Payment is not completed yet.',
            ];
        }

        $this->confirmPaid($event, $reg, $clientId, $statusRaw);

        return [
            'paid' => true,
            'gateway_status' => $statusRaw,
            'message' => 'Payment verified. Registration confirmed.',
        ];
    }

    /**
     * Apply a Maya webhook or API payload to a pending registration.
     */
    public function applyRemotePaymentPayload(
        AdminEvent $event,
        ClientAdminEventRegistration $reg,
        array $remote,
    ): bool {
        if ($reg->registration_status === 'confirmed' && in_array($reg->payment_status, ['paid', 'free'], true)) {
            return true;
        }

        $statusRaw = $this->extractGatewayStatus($remote);
        $reg->paymaya_payment_status_snapshot = Str::limit($statusRaw ?: 'UNKNOWN', 64, '');

        if (! MayaCheckoutService::payloadIndicatesPaid($remote)) {
            $reg->save();

            return false;
        }

        $this->confirmPaid($event, $reg, (string) $reg->client_id, $statusRaw);

        return true;
    }

    public function extractGatewayStatus(array $payload): string
    {
        foreach (['paymentStatus', 'status', 'payment_status'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return strtoupper(trim($payload[$key]));
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractGatewayStatus($payload['data']);
        }

        return '';
    }

    public function extractCheckoutId(array $payload): string
    {
        foreach (['checkoutId', 'checkout_id', 'id'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return trim($payload[$key]);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractCheckoutId($payload['data']);
        }

        return '';
    }

    public function extractRequestReferenceNumber(array $payload): string
    {
        foreach (['requestReferenceNumber', 'request_reference_number', 'rrn'] as $key) {
            if (! empty($payload[$key]) && is_string($payload[$key])) {
                return trim($payload[$key]);
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->extractRequestReferenceNumber($payload['data']);
        }

        return '';
    }

    private function confirmPaid(
        AdminEvent $event,
        ClientAdminEventRegistration $reg,
        string $clientId,
        string $statusRaw,
    ): void {
        $reg->registration_status = 'confirmed';
        $reg->payment_status = 'paid';
        $reg->paymaya_payment_status_snapshot = Str::limit($statusRaw ?: 'PAYMENT_SUCCESS', 64, '');

        if (\Illuminate\Support\Facades\Schema::hasColumn($reg->getTable(), 'paid_at') && $reg->paid_at === null) {
            $reg->paid_at = now('UTC');
        }

        EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, $clientId);
        $reg->save();

        Cache::forget('workout_stats:'.$clientId);
        Cache::forget('cms_events:list:'.$clientId);
    }
}
