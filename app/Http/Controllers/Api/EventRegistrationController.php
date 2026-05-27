<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventGymSelection;
use App\Models\ClientAdminEventRegistration;
use App\Models\EventProgressSubmission;
use App\Models\WorkoutLog;
use App\Models\ClientAdminEventRunningSelection;
use App\Models\ClientProfile;
use App\Services\ChallengeEnrollmentProgressService;
use App\Services\ClientNotificationService;
use App\Services\EventEnrollmentProgressService;
use App\Services\EventProgressSubmissionService;
use App\Support\ViewerChallengeProgressPresenter;
use App\Support\WorkoutJsonPresenter;
use App\Services\MayaCheckoutService;
use App\Support\RegistrationDeliveryCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EventRegistrationController extends Controller
{
    public function __construct(
        protected MayaCheckoutService $maya
    ) {}

    private function frontendBaseUrl(): string
    {
        return rtrim((string) config('app.frontend_url', ''), '/')
            ?: rtrim((string) config('app.url', ''), '/')
            ?: 'http://localhost:5173';
    }

    private function findPublishedEvent(string $id, Carbon $now): ?AdminEvent
    {
        if (!Schema::hasTable('admin_events')) {
            return null;
        }

        return AdminEvent::query()
            ->where('id', $id)
            ->active($now)
            ->first();
    }

    private function findPublishedEventForHistory(string $id, Carbon $now): ?AdminEvent
    {
        if (! Schema::hasTable('admin_events')) {
            return null;
        }

        return AdminEvent::query()
            ->where('id', $id)
            ->publishedForRegistrants($now)
            ->first();
    }

    private function registrationWindowIsOpen(AdminEvent $event, Carbon $now): bool
    {
        if ($event->registration_starts_at && $event->registration_starts_at->greaterThan($now)) {
            return false;
        }

        if ($event->registration_deadline && $event->registration_deadline->lte($now)) {
            return false;
        }

        return true;
    }

    private function eventFeePhp(AdminEvent $event): float
    {
        if (($event->fee_type ?? '') === 'free') {
            return 0.0;
        }

        return (float) ($event->fee ?? 0);
    }

    private function registrationsTableReady(): bool
    {
        return Schema::hasTable('client_admin_event_registrations');
    }

    private function registrationAllowsWizardEdits(?ClientAdminEventRegistration $reg): bool
    {
        if ($reg === null) {
            return true;
        }

        $rs = strtolower((string) ($reg->registration_status ?? ''));

        if ($rs === 'confirmed') {
            return false;
        }

        // Waiting on gateway: user may cancel Maya or abandon checkout and revise details before paying.
        if ($rs === 'pending_payment') {
            $pay = strtolower((string) ($reg->payment_status ?? ''));

            return ! in_array($pay, ['paid', 'free'], true);
        }

        return true;
    }

    /**
     * Canonical Philippine mobile for storage and validation: 09 + nine digits (11 total).
     */
    private function normalizePhilippineMobile(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        $m = '';
        if ($digits !== '') {
            if (str_starts_with($digits, '63') && strlen($digits) === 12 && ($digits[2] ?? '') === '9') {
                $m = '0'.substr($digits, 2);
            } elseif (str_starts_with($digits, '09') && strlen($digits) === 11) {
                $m = $digits;
            } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
                $m = '0'.$digits;
            }
        }

        return preg_match('/^09\d{9}$/', $m) ? $m : '';
    }

    /** @return list<string>|null Error messages joined, or null if OK */
    private function selectionsCompleteFor(Request $request, AdminEvent $event): ?array
    {
        $cat = strtolower((string) ($event->category ?? ''));
        if ($cat === 'running') {
            if (!Schema::hasTable('client_admin_event_running_selections')) {
                return ['Race selections could not be saved on this server.'];
            }
            $rd = $event->running_details;
            if (!is_array($rd)) {
                return ['This running event does not offer distance/package choices yet.'];
            }
            $row = ClientAdminEventRunningSelection::query()
                ->where('client_id', $request->user()->id)
                ->where('admin_event_id', $event->id)
                ->first();
            $pkg = trim((string) ($row?->package_key ?? ''));
            if (!$row || trim((string) $row->distance_key) === '' || $pkg === '') {
                return ['Pick a race distance and a package before confirming.'];
            }

            return null;
        }
        if ($cat === 'gym') {
            if (!Schema::hasTable('client_admin_event_gym_selections')) {
                return ['Gym selections could not be saved on this server.'];
            }
            $gd = $event->gym_details;
            if (!is_array($gd)) {
                return ['This gym event does not offer program/package choices yet.'];
            }
            $row = ClientAdminEventGymSelection::query()
                ->where('client_id', $request->user()->id)
                ->where('admin_event_id', $event->id)
                ->first();
            $pkg = trim((string) ($row?->package_key ?? ''));
            if (!$row || trim((string) $row->program_key) === '' || $pkg === '') {
                return ['Pick a program focus and membership package before confirming.'];
            }

            return null;
        }

        return null;
    }

    /** @param  array<string,mixed>|null  $participant */
    private function participantBaselineComplete(?array $participant, Request $request): bool
    {
        if ($participant === null || $participant === []) {
            return false;
        }
        $needed = ['first_name', 'last_name', 'date_of_birth', 'email', 'phone', 'country', 'street_address', 'province', 'city', 'barangay'];
        foreach ($needed as $field) {
            if (trim((string) ($participant[$field] ?? '')) === '') {
                return false;
            }
        }
        $emailPeer = strtolower(trim((string) $request->user()->email));
        $emailForm = strtolower(trim((string) ($participant['email'] ?? '')));
        $phoneStored = trim((string) ($participant['phone'] ?? ''));

        return $emailForm !== ''
            && $emailPeer === $emailForm
            && preg_match('/^09\d{9}$/', $phoneStored) === 1;
    }

    /** @param  array<string,mixed>|null  $details */
    private function deliveryBaselineComplete(?array $details): bool
    {
        if ($details === null || $details === []) {
            return false;
        }

        return trim((string) ($details['area_key'] ?? '')) !== '';
    }

    private function runningDetailsOrNull(AdminEvent $event): ?array
    {
        $rd = $event->running_details;

        return is_array($rd) && strtolower((string) ($event->category ?? '')) === 'running'
            ? $rd
            : null;
    }

    private function gymDetailsOrNull(AdminEvent $event): ?array
    {
        $gd = $event->gym_details;

        return is_array($gd) && strtolower((string) ($event->category ?? '')) === 'gym'
            ? $gd
            : null;
    }

    /**
     * Mirrors EventRunningSelectionController distance matching (kept locally to avoid refactor cycles).
     *
     * @return list<array{key: string, label?: string}>
     */
    private function normalizeDistancesFromDetails(array $rd): array
    {
        if (isset($rd['distances']) && is_array($rd['distances'])) {
            return $rd['distances'];
        }

        $legacy = strtolower(trim((string) ($rd['distance'] ?? '')));
        if ($legacy === 'other' && filled($rd['distance_custom'] ?? null)) {
            return [['key' => 'other', 'label' => (string) $rd['distance_custom']]];
        }
        if (in_array($legacy, ['3k', '5k', '10k', '21k', '42k'], true)) {
            return [['key' => $legacy]];
        }

        return [];
    }

    private function runningDistanceMatches(array $runningDetails, string $distanceKey, ?string $distanceLabel): bool
    {
        $distances = $this->normalizeDistancesFromDetails($runningDetails);
        foreach ($distances as $d) {
            $k = strtolower((string) ($d['key'] ?? ''));
            if ($k !== $distanceKey) {
                continue;
            }
            if ($k === 'other') {
                $lbl = trim((string) ($d['label'] ?? ''));

                return $lbl !== '' && $lbl === trim((string) $distanceLabel);
            }

            return true;
        }

        return false;
    }

    /**
     * Mirrors EventGymSelectionController program matching (local copy).
     *
     * @return list<array{key: string, label?: string}>
     */
    private function normalizeProgramsFromDetails(array $gd): array
    {
        if (isset($gd['programs']) && is_array($gd['programs'])) {
            return $gd['programs'];
        }

        return [];
    }

    private function gymProgramMatches(array $gymDetails, string $programKey, ?string $programLabel): bool
    {
        foreach ($this->normalizeProgramsFromDetails($gymDetails) as $p) {
            $k = strtolower((string) ($p['key'] ?? ''));
            if ($k !== $programKey) {
                continue;
            }
            if ($k === 'other') {
                $lbl = trim((string) ($p['label'] ?? ''));

                return $lbl !== '' && $lbl === trim((string) $programLabel);
            }

            return true;
        }

        return false;
    }

    private function formatRunningSelection(ClientAdminEventRunningSelection $row): array
    {
        return [
            'distance_key' => $row->distance_key,
            'distance_label' => $row->distance_label,
            'package_key' => $row->package_key,
            'package_label' => $row->package_label,
            'package_includes_shirt' => (bool) $row->package_includes_shirt,
            'shirt_size' => $row->shirt_size,
            'updated_at' => $row->updated_at?->toISOString(),
        ];
    }

    private function formatGymSelection(ClientAdminEventGymSelection $row): array
    {
        return [
            'program_key' => $row->program_key,
            'program_label' => $row->program_label,
            'package_key' => $row->package_key,
            'package_label' => $row->package_label,
            'package_includes_shirt' => (bool) $row->package_includes_shirt,
            'shirt_size' => $row->shirt_size,
            'updated_at' => $row->updated_at?->toISOString(),
        ];
    }

    /** @param  array<string,mixed>|null  $participant */
    private function buyerPayload(Request $request, ?ClientAdminEventRegistration $reg = null, ?array $participant = null): array
    {
        /** @var Client $client */
        $client = $request->user();
        $client->loadMissing('profile');

        $pd = [];
        if (is_array($participant)) {
            $pd = $participant;
        }
        $fromReg = is_array($reg?->participant_details) ? $reg->participant_details : [];
        if ($pd === [] && $fromReg !== []) {
            $pd = $fromReg;
        }

        $pf = $client->profile;
        $fn = trim((string) ($pd['first_name'] ?? $pf?->first_name ?? ''));
        $ln = trim((string) ($pd['last_name'] ?? $pf?->last_name ?? ''));
        $digits = preg_replace('/\D+/', '', (string) ($pd['phone'] ?? ''));

        $emailRaw = trim((string) ($pd['email'] ?? ''));
        if ($emailRaw === '') {
            $emailRaw = trim((string) ($client->email ?? ''));
        }

        $phoneIntl = $this->formatPhilippineMobileForPaymaya($digits);

        $contact = [];
        if ($phoneIntl !== null && $phoneIntl !== '') {
            $contact['phone'] = Str::limit($phoneIntl, 32, '');
        }
        if ($emailRaw !== '') {
            $contact['email'] = Str::limit($emailRaw, 180, '');
        }

        return array_filter([
            'firstName' => $fn !== '' ? Str::limit($fn, 120, '') : null,
            'lastName' => $ln !== '' ? Str::limit($ln, 120, '') : null,
            'contact' => $contact === [] ? null : $contact,
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * Maya / PayMaya Checkout expects E.164-style mobile (example: +639181234567).
     *
     * @internal
     */
    private function formatPhilippineMobileForPaymaya(string $digitsOnly): ?string
    {
        $digitsOnly = preg_replace('/\D+/', '', $digitsOnly) ?? '';
        if ($digitsOnly === '') {
            return null;
        }
        if (str_starts_with($digitsOnly, '63')) {
            return '+'.$digitsOnly;
        }
        if (str_starts_with($digitsOnly, '09')) {
            return '+63'.substr($digitsOnly, 1);
        }
        // 10-digit mobile stored without leading 0 (9xxxxxxxxx)
        if (strlen($digitsOnly) === 10 && str_starts_with($digitsOnly, '9')) {
            return '+63'.$digitsOnly;
        }

        return '+'.$digitsOnly;
    }

    /** @param  array<string,mixed>|null  $participant */
    private function persistProfileExtras(Request $request, array $participant): void
    {
        if (! Schema::hasTable('client_profiles')) {
            return;
        }
        /** @var ClientProfile $profile */
        $profile = ClientProfile::query()->firstOrCreate([
            'client_id' => $request->user()->id,
        ], []);

        $fields = [];
        foreach (['first_name', 'last_name', 'date_of_birth', 'phone', 'country', 'street_address', 'province', 'city', 'barangay'] as $col) {
            if (! array_key_exists($col, $participant)) {
                continue;
            }
            $trim = trim((string) ($participant[$col] ?? ''));
            if ($trim === '') {
                continue;
            }
            $fields[$col] = $col === 'date_of_birth' ? $participant[$col] : Str::limit($trim, $col === 'street_address' ? 240 : 120, '');
        }

        if ($fields !== []) {
            $profile->fill($fields);
            $profile->save();
        }
    }

    public function saveParticipant(Request $request, string $id)
    {
        if (!$this->registrationsTableReady()) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $windowOpen = $this->registrationWindowIsOpen($event, $now);
        $existing = ClientAdminEventRegistration::query()->where('client_id', $request->user()->id)->where('admin_event_id', $event->id)->first();
        $pendingPaymentResume = $existing
            && $existing->registration_status === 'pending_payment'
            && $existing->payment_status === 'pending_checkout';

        if (!$windowOpen && !$pendingPaymentResume) {
            return response()->json(['success' => false, 'message' => 'Registration is not open for this event.'], 422);
        }

        if (! $this->registrationAllowsWizardEdits($existing)) {
            return response()->json([
                'success' => false,
                'message' => 'Participant details cannot be edited after registering.',
            ], 422);
        }

        $participantIn = $request->input('participant');
        if (! is_array($participantIn)) {
            $participantIn = [];
        }
        $participantIn['phone'] = $this->normalizePhilippineMobile((string) ($participantIn['phone'] ?? ''));
        $request->merge(['participant' => $participantIn]);

        $namePattern = '/^[\p{L}\s\x{002D}\x{0027}\x{002E}\x{002C}\x{0022}]+$/u';
        $placePattern = '/^[\p{L}\p{N}\s\x{002D}\x{0027}\x{002E}\/\(\)\,\x{0023}]+$/u';
        $dobYoungestBoundary = $now->copy()->subYears(12)->startOfDay()->toDateString();
        $dobOldestBoundary = $now->copy()->subYears(115)->startOfDay()->toDateString();

        $validator = Validator::make($request->all(), [
            'participant.first_name' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$namePattern],
            'participant.last_name' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$namePattern],
            'participant.date_of_birth' => [
                'required',
                'date',
                'before_or_equal:today',
                'after_or_equal:'.$dobOldestBoundary,
                'before_or_equal:'.$dobYoungestBoundary,
            ],
            'participant.email' => 'required|email|max:180',
            'participant.phone' => ['required', 'string', 'regex:/^09\d{9}$/'],
            'participant.country' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$placePattern],
            'participant.street_address' => ['required', 'string', 'min:8', 'max:240', 'regex:/\p{L}/u', 'regex:/^(?!\d+$).+$/'],
            'participant.province' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$placePattern],
            'participant.city' => ['required', 'string', 'min:2', 'max:100', 'regex:'.$placePattern],
            'participant.barangay' => ['required', 'string', 'min:2', 'max:120', 'regex:'.$placePattern],
            'wizard_running_distance.distance_key' => 'nullable|string|max:24',
            'wizard_running_distance.distance_label' => 'nullable|string|max:120',
            'wizard_gym_program.program_key' => 'nullable|string|max:24',
            'wizard_gym_program.program_label' => 'nullable|string|max:120',
        ], [
            'participant.phone.regex' => 'Use a Philippine mobile starting with 09 (11 digits).',
            'participant.first_name.regex' => 'Letters and basic name punctuation only.',
            'participant.last_name.regex' => 'Letters and basic name punctuation only.',
            'participant.country.regex' => 'Invalid characters.',
            'participant.province.regex' => 'Invalid characters.',
            'participant.city.regex' => 'Invalid characters.',
            'participant.barangay.regex' => 'Invalid characters.',
            'participant.street_address.min' => 'Street address: at least :min characters.',
            'participant.date_of_birth.before_or_equal' => 'Must be at least 12 years old.',
            'participant.date_of_birth.after_or_equal' => 'Birth date looks too far in the past.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Check your details and try again.'], 422);
        }

        $validated = $validator->validated();
        /** @var array<string,mixed> $participantRaw */
        $participantRaw = is_array($validated['participant']) ? $validated['participant'] : [];

        $emailPeer = strtolower(trim((string) $request->user()->email));
        if (strtolower(trim((string) ($participantRaw['email'] ?? ''))) !== $emailPeer) {
            return response()->json([
                'success' => false,
                'message' => 'Registration email must match your signed-in Fitness 365 Pro account.',
            ], 422);
        }

        $cat = strtolower((string) ($event->category ?? ''));
        $rd = $this->runningDetailsOrNull($event);
        $gd = $this->gymDetailsOrNull($event);

        if ($cat === 'running' && is_array($rd)) {
            $wk = $request->input('wizard_running_distance');
            if (! is_array($wk)) {
                return response()->json(['success' => false, 'message' => 'Race distance selection is required.'], 422);
            }
            $distanceKey = strtolower(trim((string) ($wk['distance_key'] ?? '')));
            $distanceLabel = isset($wk['distance_label']) ? trim((string) $wk['distance_label']) : null;
            if ($distanceKey === '' || ! $this->runningDistanceMatches($rd, $distanceKey, $distanceLabel)) {
                return response()->json(['success' => false, 'message' => 'The selected distance is not offered for this event.'], 422);
            }

            $participantRaw['wizard_running_distance'] = [
                'distance_key' => $distanceKey,
                'distance_label' => $distanceKey === 'other' ? $distanceLabel : null,
            ];
        }

        if ($cat === 'gym' && is_array($gd)) {
            $wg = $request->input('wizard_gym_program');
            if (! is_array($wg)) {
                return response()->json(['success' => false, 'message' => 'Program selection is required.'], 422);
            }
            $programKey = strtolower(trim((string) ($wg['program_key'] ?? '')));
            $programLabel = isset($wg['program_label']) ? trim((string) $wg['program_label']) : null;
            if ($programKey === '' || ! $this->gymProgramMatches($gd, $programKey, $programLabel)) {
                return response()->json(['success' => false, 'message' => 'The selected program is not offered for this event.'], 422);
            }

            $participantRaw['wizard_gym_program'] = [
                'program_key' => $programKey,
                'program_label' => $programKey === 'other' ? $programLabel : null,
            ];
        }

        $participantRow = [];
        foreach ($participantRaw as $key => $value) {
            if (is_array($value)) {
                $participantRow[$key] = $value;
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $participantRow[$key] = Str::limit(trim($value), $key === 'street_address' ? 240 : 180, '');
        }

        if (! Schema::hasColumn('client_admin_event_registrations', 'participant_details')) {
            return response()->json([
                'success' => false,
                'message' => 'Participant storage missing. Ask an admin to deploy the latest migrations.',
            ], 503);
        }

        /** @var ClientAdminEventRegistration $reg */
        $reg = ClientAdminEventRegistration::query()->firstOrNew([
            'client_id' => $request->user()->id,
            'admin_event_id' => $event->id,
        ]);

        $rsCol = Schema::hasColumn('client_admin_event_registrations', 'registration_status');

        if (! $reg->exists) {
            if ($rsCol) {
                $reg->registration_status = 'draft';
            }
            $reg->payment_status = 'unpaid';
        } elseif (
            $rsCol
            && strtolower((string) ($reg->registration_status ?? '')) === 'draft'
            && strtolower((string) ($reg->payment_status ?? '')) === 'free'
        ) {
            $reg->payment_status = 'unpaid';
        }

        $prior = is_array($reg->participant_details) ? $reg->participant_details : [];
        $reg->participant_details = array_merge($prior, $participantRow);
        $reg->save();

        $this->persistProfileExtras($request, [
            'first_name' => (string) ($participantRaw['first_name'] ?? ''),
            'last_name' => (string) ($participantRaw['last_name'] ?? ''),
            'date_of_birth' => (string) ($participantRaw['date_of_birth'] ?? ''),
            'phone' => (string) ($participantRaw['phone'] ?? ''),
            'country' => (string) ($participantRaw['country'] ?? ''),
            'street_address' => (string) ($participantRaw['street_address'] ?? ''),
            'province' => (string) ($participantRaw['province'] ?? ''),
            'city' => (string) ($participantRaw['city'] ?? ''),
            'barangay' => (string) ($participantRaw['barangay'] ?? ''),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Participant info saved.',
            'data' => [
                'registration' => [
                    'registration_status' => $reg->registration_status,
                    'payment_status' => $reg->payment_status,
                    'participant_details' => $reg->participant_details,
                    'delivery_details' => $reg->delivery_details,
                    'delivery_fee_snapshot' => $reg->delivery_fee_snapshot !== null ? (float) $reg->delivery_fee_snapshot : null,
                ],
            ],
        ]);
    }

    public function saveDelivery(Request $request, string $id)
    {
        if (!$this->registrationsTableReady()) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $windowOpen = $this->registrationWindowIsOpen($event, $now);
        $existing = ClientAdminEventRegistration::query()->where('client_id', $request->user()->id)->where('admin_event_id', $event->id)->first();
        $pendingPaymentResume = $existing
            && $existing->registration_status === 'pending_payment'
            && $existing->payment_status === 'pending_checkout';

        if (!$windowOpen && !$pendingPaymentResume) {
            return response()->json(['success' => false, 'message' => 'Registration is not open for this event.'], 422);
        }

        if (! $this->registrationAllowsWizardEdits($existing)) {
            return response()->json([
                'success' => false,
                'message' => 'Delivery preferences cannot be edited after registering.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'delivery.area_key' => 'required|string|max:64',
            'delivery.ship_same_as_registration' => 'sometimes|boolean',
            'delivery.delivery_notes' => 'nullable|string|max:500',
            'delivery.delivery_address_line' => 'nullable|string|max:240',
            'delivery.delivery_province' => 'nullable|string|max:120',
            'delivery.delivery_city' => 'nullable|string|max:120',
            'delivery.delivery_barangay' => 'nullable|string|max:120',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        $validated = $validator->validated();
        /** @var array<string,mixed> $deliveryPayload */
        $deliveryPayload = is_array($validated['delivery']) ? $validated['delivery'] : [];

        /** @var ClientAdminEventRegistration $reg */
        $reg = ClientAdminEventRegistration::query()->firstOrNew([
            'client_id' => $request->user()->id,
            'admin_event_id' => $event->id,
        ]);

        if (! $reg->exists) {
            return response()->json([
                'success' => false,
                'message' => 'Fill your participant profile first.',
            ], 422);
        }

        if (! Schema::hasColumn('client_admin_event_registrations', 'participant_details')) {
            return response()->json(['success' => false, 'message' => 'Registration payload storage is outdated. Run migrations.'], 503);
        }

        $participant = is_array($reg->participant_details) ? $reg->participant_details : [];
        if (! $this->participantBaselineComplete($participant, $request)) {
            return response()->json(['success' => false, 'message' => 'Complete participant details before delivery options.'], 422);
        }

        $areaKey = strtolower(trim((string) $deliveryPayload['area_key']));
        $areasSource = $event->delivery_areas;
        if ($areasSource instanceof \Illuminate\Support\Collection) {
            $areasSource = $areasSource->toArray();
        }
        $areasSourceArray = is_array($areasSource) ? $areasSource : null;

        $fee = RegistrationDeliveryCatalog::feeByKey($areasSourceArray, $areaKey);

        if ($fee === null) {
            return response()->json(['success' => false, 'message' => 'That delivery zone is not available for this event.'], 422);
        }

        $areas = RegistrationDeliveryCatalog::resolve($areasSourceArray);
        $label = '';
        foreach ($areas as $a) {
            if ($a['key'] === $areaKey) {
                $label = (string) $a['label'];
                break;
            }
        }

        $shipSame = (bool) ($deliveryPayload['ship_same_as_registration'] ?? true);
        $note = isset($deliveryPayload['delivery_notes']) ? Str::limit(trim((string) $deliveryPayload['delivery_notes']), 500, '') : null;

        $structured = [
            'area_key' => $areaKey,
            'area_label' => $label,
            'ship_same_as_registration' => $shipSame,
            'delivery_notes' => $note,
            'delivery_address_line' => $shipSame ? null : trim((string) ($deliveryPayload['delivery_address_line'] ?? '')),
            'delivery_province' => $shipSame ? null : trim((string) ($deliveryPayload['delivery_province'] ?? '')),
            'delivery_city' => $shipSame ? null : trim((string) ($deliveryPayload['delivery_city'] ?? '')),
            'delivery_barangay' => $shipSame ? null : trim((string) ($deliveryPayload['delivery_barangay'] ?? '')),
        ];

        if (! $shipSame) {
            if (
                trim((string) $structured['delivery_address_line']) === ''
                || trim((string) $structured['delivery_province']) === ''
                || trim((string) $structured['delivery_city']) === ''
                || trim((string) $structured['delivery_barangay']) === ''
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Enter the full courier address because it differs from your registration address.',
                ], 422);
            }
        }

        $reg->delivery_details = array_filter($structured, fn ($v) => $v !== null && $v !== '');
        $reg->delivery_fee_snapshot = $fee;

        if (
            Schema::hasColumn('client_admin_event_registrations', 'registration_status')
            && strtolower((string) ($reg->registration_status ?? '')) !== 'confirmed'
        ) {
            $reg->registration_status = 'draft';
        }

        $reg->save();

        return response()->json([
            'success' => true,
            'message' => 'Delivery preferences saved.',
            'data' => [
                'registration' => [
                    'registration_status' => $reg->registration_status,
                    'payment_status' => $reg->payment_status,
                    'participant_details' => $reg->participant_details,
                    'delivery_details' => $reg->delivery_details,
                    'delivery_fee_snapshot' => (float) $fee,
                    'registration_fee_php' => $this->eventFeePhp($event),
                    'estimated_total_php' => round($this->eventFeePhp($event) + (float) $fee, 2),
                ],
            ],
        ]);
    }

    /** @param  array<string,mixed>|null  $participant */
    private function participantWizardSatisfied(Request $request, AdminEvent $event, ?array $participant): bool
    {
        if (! $this->participantBaselineComplete($participant, $request)) {
            return false;
        }
        $cat = strtolower((string) ($event->category ?? ''));
        $rd = $this->runningDetailsOrNull($event);
        if ($cat === 'running' && is_array($rd)) {
            $wk = $participant['wizard_running_distance'] ?? [];
            $distanceKey = strtolower(trim((string) ($wk['distance_key'] ?? '')));
            if ($distanceKey === '') {
                return false;
            }
            $lbl = isset($wk['distance_label']) ? trim((string) $wk['distance_label']) : null;

            return $this->runningDistanceMatches($rd, $distanceKey, $lbl);
        }

        $gd = $this->gymDetailsOrNull($event);
        if ($cat === 'gym' && is_array($gd)) {
            $wk = $participant['wizard_gym_program'] ?? [];
            $programKey = strtolower(trim((string) ($wk['program_key'] ?? '')));
            if ($programKey === '') {
                return false;
            }
            $lbl = isset($wk['program_label']) ? trim((string) $wk['program_label']) : null;

            return $this->gymProgramMatches($gd, $programKey, $lbl);
        }

        return true;
    }

    public function state(Request $request, string $id)
    {
        if (!Schema::hasTable('admin_events')) {
            return response()->json(['success' => false, 'message' => 'Events are not available.'], 503);
        }

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $windowOpen = $this->registrationWindowIsOpen($event, $now);
        $colReady = Schema::hasTable('client_admin_event_registrations');
        /** @var ClientAdminEventRegistration|null $reg */
        $reg = $colReady ? ClientAdminEventRegistration::query()
            ->where('client_id', $request->user()->id)
            ->where('admin_event_id', $id)
            ->first() : null;

        $pendingPaymentResume = $reg
            && $reg->registration_status === 'pending_payment'
            && $reg->payment_status === 'pending_checkout';

        $running = null;
        if (Schema::hasTable('client_admin_event_running_selections')) {
            $r = ClientAdminEventRunningSelection::query()
                ->where('client_id', $request->user()->id)
                ->where('admin_event_id', $id)
                ->first();
            if ($r) {
                $running = $this->formatRunningSelection($r);
            }
        }

        $gym = null;
        if (Schema::hasTable('client_admin_event_gym_selections')) {
            $g = ClientAdminEventGymSelection::query()
                ->where('client_id', $request->user()->id)
                ->where('admin_event_id', $id)
                ->first();
            if ($g) {
                $gym = $this->formatGymSelection($g);
            }
        }

        $participantDetails = Schema::hasColumn('client_admin_event_registrations', 'participant_details')
            ? (is_array($reg?->participant_details) ? $reg->participant_details : null)
            : null;
        $deliveryDetails = Schema::hasColumn('client_admin_event_registrations', 'delivery_details')
            ? (is_array($reg?->delivery_details) ? $reg->delivery_details : null)
            : null;
        $deliveryFee = Schema::hasColumn('client_admin_event_registrations', 'delivery_fee_snapshot') && $reg?->delivery_fee_snapshot !== null
            ? (float) $reg->delivery_fee_snapshot
            : null;
        $baseFee = $this->eventFeePhp($event);
        $totalPreview = $deliveryFee !== null ? round($baseFee + $deliveryFee, 2) : round($baseFee, 2);

        return response()->json([
            'success' => true,
            'data' => [
                'registration_window_open' => $windowOpen || $pendingPaymentResume,
                'registration' => $reg ? [
                    'registration_status' => $reg->registration_status,
                    'payment_status' => $reg->payment_status,
                    'amount_snapshot' => $reg->amount_snapshot !== null ? (float) $reg->amount_snapshot : null,
                    'paymaya_checkout_id' => $reg->paymaya_checkout_id,
                    'participant_details' => $participantDetails,
                    'delivery_details' => $deliveryDetails,
                    'delivery_fee_snapshot' => $deliveryFee,
                    'registration_fee_php' => $baseFee,
                    'estimated_total_php' => $deliveryFee !== null ? $totalPreview : null,
                ] : [
                    'registration_status' => null,
                    'payment_status' => null,
                    'amount_snapshot' => null,
                    'paymaya_checkout_id' => null,
                    'participant_details' => null,
                    'delivery_details' => null,
                    'delivery_fee_snapshot' => null,
                    'registration_fee_php' => $baseFee,
                    'estimated_total_php' => round($baseFee, 2),
                ],
                'confirmed' => $reg && $reg->registration_status === 'confirmed',
                'needs_payment_setup' => $this->eventFeePhp($event) > 0 && !$this->maya->configured(),
                'payment_mock_active' => $this->maya->mocking(),
                'requires_category_selections' => in_array(strtolower((string) $event->category), ['running', 'gym'], true),
                'running_selection' => $running,
                'gym_selection' => $gym,
                'delivery_areas_catalog' => RegistrationDeliveryCatalog::resolve(
                    ! is_array($event->delivery_areas) ? null : $event->delivery_areas
                ),
            ],
        ]);
    }

    public function confirm(Request $request, string $id)
    {
        if (!Schema::hasTable('client_admin_event_registrations')) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        if (!$this->registrationWindowIsOpen($event, $now)) {
            return response()->json(['success' => false, 'message' => 'Registration is not open for this event.'], 422);
        }

        /** @var ClientAdminEventRegistration $reg */
        $reg = ClientAdminEventRegistration::query()->firstOrNew([
            'client_id' => $request->user()->id,
            'admin_event_id' => $event->id,
        ]);

        if ($reg->exists && $reg->registration_status === 'confirmed') {
            return response()->json([
                'success' => true,
                'message' => 'You are already registered for this event.',
                'data' => [
                    'confirmed' => true,
                    'requires_payment' => false,
                    'participants_count' => ClientAdminEventRegistration::query()
                        ->where('admin_event_id', $event->id)
                        ->where('registration_status', 'confirmed')
                        ->count(),
                ],
            ]);
        }

        $participantSnap = Schema::hasColumn('client_admin_event_registrations', 'participant_details')
            ? (is_array($reg->participant_details) ? $reg->participant_details : [])
            : [];

        $deliverySnap = Schema::hasColumn('client_admin_event_registrations', 'delivery_details')
            ? (is_array($reg->delivery_details) ? $reg->delivery_details : [])
            : [];

        if (! $this->participantWizardSatisfied($request, $event, $participantSnap ?: null)) {
            return response()->json([
                'success' => false,
                'message' => 'Participant profile is incomplete.',
            ], 422);
        }

        if (! $this->deliveryBaselineComplete($deliverySnap)) {
            return response()->json([
                'success' => false,
                'message' => 'Choose a kit delivery or courier zone.',
            ], 422);
        }

        $errs = $this->selectionsCompleteFor($request, $event);
        if ($errs !== null) {
            return response()->json(['success' => false, 'message' => $errs[0], 'messages' => $errs], 422);
        }

        $deliveryKey = strtolower(trim((string) ($deliverySnap['area_key'] ?? '')));

        $src = $event->delivery_areas;
        if ($src instanceof \Illuminate\Support\Collection) {
            $src = $src->toArray();
        }
        $expectedFee = RegistrationDeliveryCatalog::feeByKey(is_array($src) ? $src : null, $deliveryKey);

        if ($expectedFee === null) {
            return response()->json(['success' => false, 'message' => 'Stored delivery zone is invalid. Refresh and try again.'], 422);
        }

        if ($reg->delivery_fee_snapshot === null || round((float) $reg->delivery_fee_snapshot, 2) !== round((float) $expectedFee, 2)) {
            return response()->json(['success' => false, 'message' => 'Delivery fee is stale. Reload this page before confirming.'], 422);
        }

        $baseAmount = round($this->eventFeePhp($event), 2);
        $deliveryAmt = round((float) $reg->delivery_fee_snapshot, 2);
        $amount = round($baseAmount + $deliveryAmt, 2);
        $requiresGateway = $amount > 0.00001;

        if ($reg->exists && $reg->registration_status === 'pending_payment') {
            $reg->amount_snapshot = $amount;
            EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, (string) $request->user()->id);
            $reg->save();

            return response()->json([
                'success' => true,
                'message' => $requiresGateway ? 'Continue to Maya Checkout to pay your registration.' : 'Registration finalized.',
                'data' => [
                    'confirmed' => false,
                    'requires_payment' => $requiresGateway,
                    'amount_php' => $amount,
                    'registration_fee_php' => $baseAmount,
                    'delivery_fee_php' => $deliveryAmt,
                ],
            ]);
        }

        if (! $requiresGateway) {
            $reg->fill([
                'registration_status' => 'confirmed',
                'payment_status' => 'free',
                'amount_snapshot' => $amount,
                'paymaya_checkout_id' => null,
                'paymaya_rrn' => null,
                'paymaya_payment_status_snapshot' => null,
            ]);
            EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, (string) $request->user()->id);
            $reg->save();

            return response()->json([
                'success' => true,
                'message' => 'Registration confirmed.',
                'data' => [
                    'confirmed' => true,
                    'requires_payment' => false,
                    'amount_php' => $amount,
                    'registration_fee_php' => $baseAmount,
                    'delivery_fee_php' => $deliveryAmt,
                    'participants_count' => ClientAdminEventRegistration::query()
                        ->where('admin_event_id', $event->id)
                        ->where('registration_status', 'confirmed')
                        ->count(),
                ],
            ]);
        }

        if (! $this->maya->configured()) {
            return response()->json([
                'success' => false,
                'message' => 'Online payment is not configured yet (Maya Checkout keys missing on the server).',
            ], 503);
        }

        if (! $reg->paymaya_rrn) {
            $reg->paymaya_rrn = 'f365-' . Str::lower(Str::random(28));
        }

        $reg->fill([
            'registration_status' => 'pending_payment',
            'payment_status' => 'pending_checkout',
            'amount_snapshot' => $amount,
        ]);
        EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, (string) $request->user()->id);
        $reg->save();

        return response()->json([
            'success' => true,
            'message' => 'Continue to Maya Checkout to complete payment.',
            'data' => [
                'confirmed' => false,
                'requires_payment' => true,
                'amount_php' => $amount,
                'registration_fee_php' => $baseAmount,
                'delivery_fee_php' => $deliveryAmt,
            ],
        ]);
    }

    public function paymayaCheckout(Request $request, string $id)
    {
        if (!Schema::hasTable('client_admin_event_registrations')) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        if (!$this->maya->configured()) {
            return response()->json([
                'success' => false,
                'message' => 'PayMaya / Maya Checkout is not configured (set PAYMAYA_PUBLIC_KEY and PAYMAYA_SECRET_KEY).',
            ], 503);
        }

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (!$event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        $errs = $this->selectionsCompleteFor($request, $event);
        if ($errs !== null) {
            return response()->json(['success' => false, 'message' => $errs[0], 'messages' => $errs], 422);
        }

        /** @var ClientAdminEventRegistration|null $reg */
        $reg = ClientAdminEventRegistration::query()->where('client_id', $request->user()->id)
            ->where('admin_event_id', $event->id)
            ->first();

        $pendingPaymentResume = $reg
            && $reg->registration_status === 'pending_payment'
            && $reg->payment_status === 'pending_checkout';

        if (!$this->registrationWindowIsOpen($event, $now) && !$pendingPaymentResume) {
            return response()->json(['success' => false, 'message' => 'Registration is not open for this event.'], 422);
        }

        if (!$reg || $reg->registration_status !== 'pending_payment' || $reg->payment_status !== 'pending_checkout') {
            return response()->json([
                'success' => false,
                'message' => 'Confirm your summary first before opening checkout.',
            ], 422);
        }

        $amountDue = round((float) ($reg->amount_snapshot ?? 0), 2);
        if ($amountDue <= 0.00001) {
            return response()->json(['success' => false, 'message' => 'No payment balance is owed for this registration.'], 422);
        }

        $participantSnap = is_array($reg->participant_details) ? $reg->participant_details : [];

        $baseAmount = round($this->eventFeePhp($event), 2);
        $deliveryAmt = round((float) ($reg->delivery_fee_snapshot ?? 0), 2);
        $expectedAmount = round($baseAmount + $deliveryAmt, 2);
        $amount = sprintf('%.2f', max((float) $reg->amount_snapshot, $expectedAmount));

        $frontend = $this->frontendBaseUrl();

        $path = '/challenges/' . rawurlencode($event->id) . '/register';
        $redirectSuccess = $frontend . $path . '?payment=success';
        $redirectFailure = $frontend . $path . '?payment=failed';
        $redirectCancel = $frontend . $path . '?payment=cancelled';

        $paymayaErr = null;
        $json = $this->maya->createCheckout(
            $amount,
            'PHP',
            $this->buyerPayload($request, $reg, $participantSnap),
            $redirectSuccess,
            $redirectFailure,
            $redirectCancel,
            $reg->paymaya_rrn ?: ('f365-' . Str::lower(Str::random(28))),
            $paymayaErr
        );

        if ($json === null) {
            $detail = trim((string) ($paymayaErr ?? ''));
            $message = $detail !== ''
                ? 'Unable to create Maya Checkout. '.$detail
                : 'Unable to create Maya Checkout. Try again shortly.';

            return response()->json(['success' => false, 'message' => $message], 502);
        }

        $checkoutId = $json['checkoutId'] ?? ($json['id'] ?? null);
        $redirectUrl = $json['redirectUrl'] ?? null;

        if (! is_string($checkoutId) || $checkoutId === '' || ! is_string($redirectUrl) || $redirectUrl === '') {
            return response()->json(['success' => false, 'message' => 'Unexpected Maya Checkout response.'], 502);
        }

        $reg->paymaya_checkout_id = $checkoutId;
        $reg->save();

        return response()->json([
            'success' => true,
            'message' => 'Redirecting you to Maya Checkout.',
            'data' => [
                'checkout_id' => $checkoutId,
                'redirect_url' => $redirectUrl,
            ],
        ]);
    }

    public function paymayaVerify(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'checkout_id' => 'required|string|max:80',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Validation failed'], 422);
        }

        if (! Schema::hasTable('client_admin_event_registrations')) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        if (! $this->maya->configured()) {
            return response()->json(['success' => false, 'message' => 'Payment verification is unavailable.'], 503);
        }

        $checkoutId = (string) $request->input('checkout_id');

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        /** @var ClientAdminEventRegistration|null $reg */
        $reg = ClientAdminEventRegistration::query()
            ->where('client_id', $request->user()->id)
            ->where('admin_event_id', $event->id)
            ->first();

        if (! $reg || trim((string) $reg->paymaya_checkout_id) !== $checkoutId) {
            return response()->json([
                'success' => false,
                'message' => 'Checkout does not match your registration.',
            ], 422);
        }

        if ($reg->registration_status === 'confirmed' && ($reg->payment_status === 'paid' || $reg->payment_status === 'free')) {
            return response()->json([
                'success' => true,
                'message' => 'Registration already confirmed.',
                'data' => [
                    'paid' => true,
                    'participants_count' => ClientAdminEventRegistration::query()
                        ->where('admin_event_id', $event->id)
                        ->where('registration_status', 'confirmed')
                        ->count(),
                ],
            ]);
        }

        $remote = $this->maya->retrieveCheckout($checkoutId);
        if ($remote === null) {
            return response()->json(['success' => false, 'message' => 'Unable to verify payment with Maya yet.'], 502);
        }

        $statusRaw = '';
        foreach (['paymentStatus', 'status', 'payment_status'] as $k) {
            if (! empty($remote[$k]) && is_string($remote[$k])) {
                $statusRaw = (string) $remote[$k];
                break;
            }
        }

        $reg->paymaya_payment_status_snapshot = Str::limit($statusRaw ?: 'UNKNOWN', 64, '');
        $reg->save();

        if (! MayaCheckoutService::checkoutIndicatesPaid($remote)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment is not completed yet.',
                'data' => [
                    'paid' => false,
                    'gateway_status' => $statusRaw,
                ],
            ], 422);
        }

        $reg->registration_status = 'confirmed';
        $reg->payment_status = 'paid';
        EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, (string) $request->user()->id);
        $reg->save();

        return response()->json([
            'success' => true,
            'message' => 'Payment verified. You are registered for this event.',
            'data' => [
                'paid' => true,
                'participants_count' => ClientAdminEventRegistration::query()
                    ->where('admin_event_id', $event->id)
                    ->where('registration_status', 'confirmed')
                    ->count(),
            ],
        ]);
    }

    /**
     * Queue distance / pace progress toward a challenge (requires admin approval before it counts).
     */
    public function logProgress(Request $request, string $id)
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        $validator = Validator::make($request->all(), [
            'logged_distance_km' => 'nullable|numeric|min:0|max:20000',
            'add_distance_km' => 'nullable|numeric|min:0|max:2000',
            'pace_min_per_km' => 'nullable|numeric|min:2|max:30',
        ]);

        $validator->after(function ($validator) use ($request) {
            $hasLogged = $request->filled('logged_distance_km');
            $hasAdd = $request->filled('add_distance_km');
            if ($hasLogged xor $hasAdd) {
                return;
            }
            $validator->errors()->add(
                'logged_distance_km',
                'Send either logged_distance_km (total kilometres so far) or add_distance_km (add to your total).'
            );
        });

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors(), 'message' => 'Check the values and try again.'], 422);
        }

        $now = now('UTC');
        $event = $this->findPublishedEvent($id, $now);
        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        /** @var ClientAdminEventRegistration|null $reg */
        $reg = ClientAdminEventRegistration::query()
            ->where('client_id', $request->user()->id)
            ->where('admin_event_id', $event->id)
            ->first();

        if (! $reg || strtolower((string) $reg->registration_status) !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Only confirmed entrants can update challenge progress.',
            ], 422);
        }

        if (! Schema::hasColumn($reg->getTable(), 'progress_logged_km')) {
            return response()->json(['success' => false, 'message' => 'Progress storage is not migrated yet.'], 503);
        }

        EventEnrollmentProgressService::syncRegistrationGoals($event, $reg, (string) $request->user()->id);
        $reg->refresh();

        $validated = $validator->validated();
        $goal = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;
        $paceOpt = isset($validated['pace_min_per_km']) ? (float) $validated['pace_min_per_km'] : null;

        if (! EventProgressSubmissionService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Progress queue is not available yet.'], 503);
        }

        if (! empty($validated['add_distance_km'])) {
            $add = round((float) $validated['add_distance_km'], 4);
            EventProgressSubmissionService::queueManualSubmission($reg, $add, $paceOpt > 0 ? $paceOpt : null);
        } else {
            $logged = round((float) ($validated['logged_distance_km'] ?? 0), 4);
            $maxCap = ChallengeEnrollmentProgressService::registrationProgressCapKm($goal);
            if ($maxCap !== null && $logged > $maxCap) {
                return response()->json([
                    'success' => false,
                    'message' => 'Logged distance is far above your challenge goal. Please verify your entry.',
                ], 422);
            }
            $currentApproved = $reg->progress_logged_km !== null ? (float) $reg->progress_logged_km : 0.0;
            $delta = round($logged - $currentApproved, 4);
            if (abs($delta) < 0.0001) {
                $pendingKm = EventProgressSubmissionService::sumPendingDeltaKm((string) $request->user()->id, (string) $event->id);

                return response()->json([
                    'success' => true,
                    'message' => 'No change from your approved total.',
                    'data' => [
                        'logged_distance_km' => round($currentApproved, 4),
                        'goal_distance_km' => $goal !== null ? round($goal, 4) : null,
                        'progress_percent' => $goal !== null && $goal > 0
                            ? min(100, round(($currentApproved / $goal) * 100, 1))
                            : null,
                        'pace_min_per_km' => $reg->progress_pace_min_per_km !== null ? (float) $reg->progress_pace_min_per_km : null,
                        'submission_status' => (string) ($reg->progress_submission_status ?? 'approved'),
                        'pending_queue_km' => round($pendingKm, 4),
                    ],
                ]);
            }
            EventProgressSubmissionService::queueManualSubmission($reg, $delta, $paceOpt > 0 ? $paceOpt : null);
        }

        $reg->refresh();

        $finalLogged = $reg->progress_logged_km !== null ? (float) $reg->progress_logged_km : 0.0;
        $goalFresh = $reg->progress_goal_km !== null ? (float) $reg->progress_goal_km : null;
        $pendingKm = EventProgressSubmissionService::sumPendingDeltaKm((string) $request->user()->id, (string) $event->id);

        return response()->json([
            'success' => true,
            'message' => 'Progress submitted for review. An admin will approve it before it counts toward your challenge.',
            'data' => [
                'logged_distance_km' => round($finalLogged, 4),
                'goal_distance_km' => $goalFresh !== null ? round($goalFresh, 4) : null,
                'progress_percent' => $goalFresh !== null && $goalFresh > 0
                    ? min(100, round(($finalLogged / $goalFresh) * 100, 1))
                    : null,
                'pace_min_per_km' => $reg->progress_pace_min_per_km !== null ? (float) $reg->progress_pace_min_per_km : null,
                'submission_status' => (string) ($reg->progress_submission_status ?? 'approved'),
                'pending_queue_km' => round($pendingKm, 4),
            ],
        ]);
    }

    /**
     * Full challenge journal for the authenticated viewer: progress slice, moderation timeline, linked workouts.
     */
    public function myChallengeHistory(Request $request, string $id)
    {
        $viewerId = (string) $request->user()->id;

        return $this->challengeHistoryResponse($id, $viewerId, $viewerId, false);
    }

    /**
     * Challenge progress journal for another member's public profile.
     */
    public function memberChallengeHistory(Request $request, string $clientId, string $eventId)
    {
        $viewerId = (string) $request->user()->id;
        $subjectId = (string) $clientId;

        if ($viewerId === $subjectId) {
            return $this->myChallengeHistory($request, $eventId);
        }

        if (! Client::query()->whereKey($subjectId)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'User not found.',
            ], 404);
        }

        return $this->challengeHistoryResponse($eventId, $subjectId, $viewerId, true);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    private function challengeHistoryResponse(
        string $eventId,
        string $subjectClientId,
        string $viewerClientId,
        bool $forMemberProfile,
    ) {
        if (! $this->registrationsTableReady()) {
            return response()->json(['success' => false, 'message' => 'Registration is not available.'], 503);
        }

        $now = now('UTC');
        $event = $this->findPublishedEventForHistory($eventId, $now);
        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Event not found.'], 404);
        }

        /** @var ClientAdminEventRegistration|null $reg */
        $reg = ClientAdminEventRegistration::query()
            ->where('client_id', $subjectClientId)
            ->where('admin_event_id', $event->id)
            ->first();

        if (! $reg || strtolower((string) ($reg->registration_status ?? '')) !== 'confirmed') {
            $message = $forMemberProfile
                ? 'This member is not enrolled in this challenge.'
                : 'Join this event to see your challenge history.';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $forMemberProfile ? 404 : 403);
        }

        $challengeProgress = ViewerChallengeProgressPresenter::slice($event, $reg, $subjectClientId);

        $submissions = [];
        if (EventProgressSubmissionService::tableReady()) {
            $submissions = EventProgressSubmission::query()
                ->where('client_id', $subjectClientId)
                ->where('admin_event_id', $event->id)
                ->with(['workoutLog' => fn ($q) => $q->withCount(['likes', 'comments'])])
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(fn (EventProgressSubmission $s) => $this->serializeSubmissionHistoryRow(
                    $s,
                    $viewerClientId,
                    $forMemberProfile,
                ))
                ->values()
                ->all();
        }

        $linkedWorkouts = [];
        $workoutsTable = (new WorkoutLog())->getTable();
        if (Schema::hasColumn($workoutsTable, 'admin_event_id')) {
            $linkedWorkouts = WorkoutLog::query()
                ->where('client_id', $subjectClientId)
                ->where('admin_event_id', $event->id)
                ->with(['linkedAdminEvent' => fn ($q) => $q->select('id', 'title')])
                ->withCount(['likes', 'comments'])
                ->orderByDesc('workout_date')
                ->orderByDesc('created_at')
                ->limit(80)
                ->get()
                ->map(fn (WorkoutLog $w) => WorkoutJsonPresenter::serializeForClientViewer($w, $viewerClientId))
                ->values()
                ->all();
        }

        $member = null;
        if ($forMemberProfile) {
            $subject = Client::query()->with('profile')->find($subjectClientId);
            if ($subject) {
                $member = [
                    'id' => (string) $subject->id,
                    'display_name' => ClientNotificationService::displayName($subject),
                    'profile_picture_url' => $subject->profile?->profile_picture_url,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'event' => [
                    'id' => (string) $event->id,
                    'title' => (string) $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'mileage_challenge_km' => Schema::hasColumn('admin_events', 'mileage_challenge_km')
                        && $event->mileage_challenge_km !== null
                        ? round((float) $event->mileage_challenge_km, 4)
                        : null,
                    'badges' => is_array($event->badges) ? $event->badges : [],
                ],
                'member' => $member,
                'is_member_view' => $forMemberProfile,
                'runs_count' => count($linkedWorkouts),
                'challenge_progress' => $challengeProgress,
                'submissions' => $submissions,
                'linked_workouts' => $linkedWorkouts,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSubmissionHistoryRow(
        EventProgressSubmission $submission,
        string $viewerId,
        bool $forMemberProfile = false,
    ): array {
        $row = [
            'id' => (string) $submission->id,
            'status' => (string) $submission->status,
            'source' => (string) $submission->source,
            'distance_delta_km' => round((float) $submission->distance_delta_km, 4),
            'pace_min_per_km' => $submission->pace_min_per_km !== null
                ? round((float) $submission->pace_min_per_km, 4)
                : null,
            'created_at' => $submission->created_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'review_note' => ! $forMemberProfile && $submission->status === EventProgressSubmission::STATUS_REJECTED
                ? (trim((string) ($submission->review_note ?? '')) ?: null)
                : null,
        ];

        $wl = $submission->workoutLog;
        $row['workout'] = null;
        if ($wl instanceof WorkoutLog) {
            if (! isset($wl->likes_count)) {
                $wl->loadCount(['likes', 'comments']);
            }
            $row['workout'] = WorkoutJsonPresenter::serializeForClientViewer($wl, $viewerId);
        }

        return $row;
    }

    /** @deprecated Use POST registration/confirm or the registration wizard. */
    public function register(Request $request, string $id)
    {
        return $this->confirm($request, $id);
    }
}
