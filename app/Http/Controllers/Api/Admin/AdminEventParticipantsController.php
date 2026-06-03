<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\LogsAdminActivity;
use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Services\ClientNotificationService;
use App\Services\EventEnrollmentProgressService;
use App\Services\EventRegistrationPaymentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AdminEventParticipantsController extends Controller
{
    use LogsAdminActivity;

    public function __construct(
        private readonly EventRegistrationPaymentService $registrationPayments,
    ) {}

    public function registrations(Request $request, string $id)
    {
        if (! Schema::hasTable('client_admin_event_registrations') || ! Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'Registrations not available (migrations incomplete).'], 503);
        }

        $event = AdminEvent::query()->find($id);
        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $perPage = (int) $request->input('per_page', 25);

        $q = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->with(['client.profile', 'registeredByAdmin:id,name,email'])
            ->orderByDesc('created_at');

        $paginator = $q->paginate($perPage);
        $paginator->getCollection()->transform(fn (ClientAdminEventRegistration $r) => $this->serializeRegistration($r));

        return response()->json([
            'success' => true,
            'data' => [
                'event' => $this->serializeEventSummary($event),
                'registrations' => $paginator,
            ],
        ]);
    }

    public function manualRegister(Request $request, string $id)
    {
        if (! Schema::hasTable('client_admin_event_registrations') || ! Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'Registrations not available (migrations incomplete).'], 503);
        }

        $event = AdminEvent::query()->find($id);
        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid|exists:clients,id',
            'payment_method' => 'nullable|in:cash,office,bank_transfer,free',
            'amount_received' => 'nullable|numeric|min:0|max:999999.99',
            'manual_payment_reference' => 'nullable|string|max:120',
            'admin_registration_note' => 'nullable|string|max:2000',
            'ignore_registration_window' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $now = now('UTC');
        $ignoreWindow = $request->boolean('ignore_registration_window');

        if (! $ignoreWindow && ! $this->registrationWindowIsOpen($event, $now)) {
            return response()->json([
                'success' => false,
                'message' => 'Registration is closed for this event. Enable "Override registration window" to register anyway.',
            ], 422);
        }

        /** @var Client $client */
        $client = Client::query()->with('profile')->findOrFail($request->input('client_id'));

        $existing = ClientAdminEventRegistration::query()
            ->where('client_id', $client->id)
            ->where('admin_event_id', $event->id)
            ->first();

        if ($existing && strtolower((string) ($existing->registration_status ?? '')) === 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'This member is already registered for this event.',
            ], 422);
        }

        $baseFee = $this->eventFeePhp($event);
        $isFreeEvent = $baseFee <= 0.00001;

        $paymentMethod = $isFreeEvent
            ? 'free'
            : (string) ($request->input('payment_method') ?: 'cash');

        if (! $isFreeEvent && ! in_array($paymentMethod, ['cash', 'office', 'bank_transfer'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose a payment method: cash, office, or bank transfer.',
            ], 422);
        }

        $amount = $request->filled('amount_received')
            ? round((float) $request->input('amount_received'), 2)
            : round($baseFee, 2);

        /** @var ClientAdminEventRegistration $reg */
        $reg = $existing ?? new ClientAdminEventRegistration([
            'client_id' => $client->id,
            'admin_event_id' => $event->id,
        ]);

        $participantDetails = $this->buildParticipantDetailsFromProfile($client);
        if (Schema::hasColumn($reg->getTable(), 'participant_details')) {
            $reg->participant_details = $participantDetails;
        }

        if (Schema::hasColumn($reg->getTable(), 'delivery_details') && empty($reg->delivery_details)) {
            $reg->delivery_details = [
                'area_key' => 'office_pickup',
                'area_label' => 'Office / in-person registration',
                'registered_via' => 'admin_manual',
            ];
        }

        $reg->registration_status = 'confirmed';
        $reg->payment_status = $isFreeEvent ? 'free' : 'paid';
        $reg->amount_snapshot = $amount;
        $reg->paymaya_checkout_id = null;
        $reg->paymaya_rrn = null;
        $reg->paymaya_payment_status_snapshot = null;

        if (Schema::hasColumn($reg->getTable(), 'payment_method')) {
            $reg->payment_method = $paymentMethod;
        }
        if (Schema::hasColumn($reg->getTable(), 'registered_by_admin_id')) {
            $reg->registered_by_admin_id = $request->user()?->id;
        }
        if (Schema::hasColumn($reg->getTable(), 'manual_payment_reference')) {
            $reg->manual_payment_reference = $request->input('manual_payment_reference');
        }
        if (Schema::hasColumn($reg->getTable(), 'admin_registration_note')) {
            $reg->admin_registration_note = $request->input('admin_registration_note');
        }
        if (Schema::hasColumn($reg->getTable(), 'paid_at')) {
            $reg->paid_at = $isFreeEvent ? null : $now;
        }

        EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, (string) $client->id);
        $reg->save();

        ClientNotificationService::eventRegisteredManually($client, $event, $paymentMethod);

        $this->logAdminActivity($request, 'manual_event_registration', 'event_registration', $reg->id, [
            'admin_event_id' => (string) $event->id,
            'event_title' => (string) $event->title,
            'client_id' => (string) $client->id,
            'client_email' => (string) $client->email,
            'payment_method' => $paymentMethod,
            'amount' => $amount,
        ]);

        $reg->load(['client.profile', 'registeredByAdmin:id,name,email']);

        return response()->json([
            'success' => true,
            'message' => 'Member registered successfully.',
            'data' => [
                'registration' => $this->serializeRegistration($reg),
            ],
        ], 201);
    }

    /**
     * Reconcile a stuck Maya registration (pending_checkout) against the gateway.
     */
    public function syncPayment(Request $request, string $eventId, string $registrationId)
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return response()->json(['success' => false, 'message' => 'Registrations not available.'], 503);
        }

        $event = AdminEvent::query()->find($eventId);
        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $reg = ClientAdminEventRegistration::query()
            ->where('admin_event_id', $event->id)
            ->where('id', $registrationId)
            ->first();

        if (! $reg) {
            return response()->json(['success' => false, 'message' => 'Registration not found.'], 404);
        }

        $result = $this->registrationPayments->syncRegistrationPayment(
            $event,
            $reg,
            (string) $reg->client_id,
        );

        $reg->refresh()->load(['client.profile', 'registeredByAdmin:id,name,email']);

        return response()->json([
            'success' => $result['paid'],
            'message' => $result['message'],
            'data' => [
                'paid' => $result['paid'],
                'gateway_status' => $result['gateway_status'],
                'registration' => $this->serializeRegistration($reg),
            ],
        ], $result['paid'] ? 200 : 422);
    }

    protected function registrationWindowIsOpen(AdminEvent $event, Carbon $now): bool
    {
        if ($event->registration_starts_at && $event->registration_starts_at->greaterThan($now)) {
            return false;
        }

        if ($event->registration_deadline && $event->registration_deadline->lte($now)) {
            return false;
        }

        return true;
    }

    protected function eventFeePhp(AdminEvent $event): float
    {
        if (($event->fee_type ?? '') === 'free') {
            return 0.0;
        }

        return (float) ($event->fee ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildParticipantDetailsFromProfile(Client $client): array
    {
        $profile = $client->profile;

        return array_filter([
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'display_name' => $profile?->display_name,
            'email' => $client->email,
            'phone' => $profile?->phone,
            'city' => $profile?->city,
            'province' => $profile?->province,
            'country' => $profile?->country,
            'registered_via' => 'admin_manual',
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeEventSummary(AdminEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => (string) $event->title,
            'status' => (string) $event->status,
            'category' => (string) $event->category,
            'location' => (string) $event->location,
            'fee_type' => (string) ($event->fee_type ?? 'free'),
            'fee' => $event->fee !== null ? (float) $event->fee : null,
            'mileage_challenge_km' => $event->mileage_challenge_km !== null ? (float) $event->mileage_challenge_km : null,
            'registration_starts_at' => $event->registration_starts_at?->toISOString(),
            'registration_deadline' => $event->registration_deadline?->toISOString(),
            'starts_at' => $event->starts_at?->toISOString(),
            'ends_at' => $event->ends_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeRegistration(ClientAdminEventRegistration $r): array
    {
        $r->loadMissing(['client.profile', 'registeredByAdmin:id,name,email']);

        $client = $r->client;
        $profile = $client?->profile;
        $displayName = $profile
            ? trim((string) (($profile->first_name ?? '').' '.($profile->last_name ?? '')))
            : '';
        if ($displayName === '' && $profile && trim((string) ($profile->display_name ?? '')) !== '') {
            $displayName = trim((string) $profile->display_name);
        }
        if ($displayName === '' && $client?->email) {
            $displayName = explode('@', (string) $client->email)[0] ?? 'Member';
        }

        $payload = [
            'id' => (string) $r->id,
            'client' => [
                'id' => $client ? (string) $client->id : null,
                'display_name' => $displayName !== '' ? $displayName : 'Member',
                'email' => $client?->email,
            ],
            'registration_status' => (string) ($r->registration_status ?? ''),
            'payment_status' => (string) ($r->payment_status ?? ''),
            'amount_snapshot' => $r->amount_snapshot !== null ? (string) $r->amount_snapshot : null,
            'progress_logged_km' => $r->progress_logged_km !== null ? (float) $r->progress_logged_km : null,
            'progress_goal_km' => $r->progress_goal_km !== null ? (float) $r->progress_goal_km : null,
            'progress_target_label' => $r->progress_target_label,
            'progress_pace_min_per_km' => $r->progress_pace_min_per_km !== null ? (float) $r->progress_pace_min_per_km : null,
            'progress_submission_status' => $r->progress_submission_status,
            'participant_details' => $r->participant_details,
            'delivery_details' => $r->delivery_details,
            'delivery_fee_snapshot' => $r->delivery_fee_snapshot !== null ? (float) $r->delivery_fee_snapshot : null,
            'created_at' => $r->created_at?->toISOString(),
            'updated_at' => $r->updated_at?->toISOString(),
        ];

        if (Schema::hasColumn($r->getTable(), 'payment_method')) {
            $payload['payment_method'] = $r->payment_method;
        }
        if (Schema::hasColumn($r->getTable(), 'manual_payment_reference')) {
            $payload['manual_payment_reference'] = $r->manual_payment_reference;
        }
        if (Schema::hasColumn($r->getTable(), 'admin_registration_note')) {
            $payload['admin_registration_note'] = $r->admin_registration_note;
        }
        if (Schema::hasColumn($r->getTable(), 'paid_at')) {
            $payload['paid_at'] = $r->paid_at?->toISOString();
        }
        if (Schema::hasColumn($r->getTable(), 'registered_by_admin_id')) {
            $admin = $r->registeredByAdmin;
            $payload['registered_by_admin'] = $admin ? [
                'id' => (string) $admin->id,
                'name' => (string) $admin->name,
                'email' => (string) $admin->email,
            ] : null;
        }

        return $payload;
    }
}
