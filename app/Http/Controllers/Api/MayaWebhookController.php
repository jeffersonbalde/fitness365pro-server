<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Services\EventRegistrationPaymentService;
use App\Services\MayaCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MayaWebhookController extends Controller
{
    public function __construct(
        private readonly EventRegistrationPaymentService $payments,
    ) {}

    /**
     * Maya Checkout / Payments webhook (PAYMENT_SUCCESS, etc.).
     * Register this URL in Maya Business Manager.
     */
    public function handle(Request $request): JsonResponse
    {
        if (! $this->authorizeWebhook($request)) {
            Log::warning('Maya webhook rejected: invalid authorization');

            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        Log::info('Maya webhook received', [
            'keys' => array_keys($payload),
            'payment_status' => $this->payments->extractGatewayStatus($payload),
        ]);

        $checkoutId = $this->payments->extractCheckoutId($payload);
        $rrn = $this->payments->extractRequestReferenceNumber($payload);

        $reg = $this->payments->findPendingByMayaRefs($checkoutId, $rrn);
        if (! $reg) {
            // Always 200 so Maya does not keep retrying for unrelated payments.
            return response()->json(['success' => true, 'message' => 'No matching pending registration.']);
        }

        $event = AdminEvent::query()->find($reg->admin_event_id);
        if (! $event) {
            return response()->json(['success' => true, 'message' => 'Event not found for registration.']);
        }

        if (MayaCheckoutService::payloadIndicatesPaid($payload)) {
            $this->payments->applyRemotePaymentPayload($event, $reg, $payload);
            Log::info('Maya webhook confirmed registration', [
                'registration_id' => $reg->id,
                'client_id' => $reg->client_id,
                'event_id' => $reg->admin_event_id,
                'checkout_id' => $checkoutId,
                'rrn' => $rrn,
            ]);
        } else {
            $status = $this->payments->extractGatewayStatus($payload);
            if ($status !== '') {
                $reg->paymaya_payment_status_snapshot = \Illuminate\Support\Str::limit($status, 64, '');
                $reg->save();
            }
        }

        return response()->json(['success' => true]);
    }

    private function authorizeWebhook(Request $request): bool
    {
        $secret = trim((string) config('services.paymaya.webhook_secret', ''));
        if ($secret === '') {
            return true;
        }

        $headerToken = trim((string) $request->header('X-PayMaya-Webhook-Secret', ''));
        if ($headerToken !== '' && hash_equals($secret, $headerToken)) {
            return true;
        }

        $queryToken = trim((string) $request->query('token', ''));
        if ($queryToken !== '' && hash_equals($secret, $queryToken)) {
            return true;
        }

        $auth = trim((string) $request->header('Authorization', ''));
        if (str_starts_with($auth, 'Basic ')) {
            $decoded = base64_decode(substr($auth, 6), true);
            if (is_string($decoded)) {
                $parts = explode(':', $decoded, 2);
                $provided = $parts[0] ?? '';
                if ($provided !== '' && hash_equals($secret, $provided)) {
                    return true;
                }
            }
        }

        return false;
    }
}
