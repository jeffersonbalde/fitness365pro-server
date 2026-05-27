<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientBadge;
use App\Models\ClientProfile;
use App\Models\WorkoutLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

class ProfileController extends Controller
{
    private function deriveOverallExperienceLevel(?string $running, ?string $gym, ?string $others): ?string
    {
        $rank = [
            'beginner' => 1,
            'intermediate' => 2,
            'advanced' => 3,
            'expert' => 4,
        ];

        $values = array_filter([$running, $gym, $others], fn ($value) => is_string($value) && isset($rank[$value]));
        if (empty($values)) {
            return null;
        }

        usort($values, fn ($a, $b) => $rank[$b] <=> $rank[$a]);
        return $values[0];
    }

    private function deletePublicFileByUrl(?string $url, array $allowedDirectories = []): void
    {
        if (!$url) {
            return;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return;
        }

        $storagePrefix = '/storage/';
        if (str_starts_with($path, $storagePrefix)) {
            $relativePath = substr($path, strlen($storagePrefix));
            if ($relativePath) {
                if (!empty($allowedDirectories)) {
                    $isAllowed = false;
                    foreach ($allowedDirectories as $directory) {
                        if (str_starts_with($relativePath, trim($directory, '/') . '/')) {
                            $isAllowed = true;
                            break;
                        }
                    }
                    if (!$isAllowed) {
                        return;
                    }
                }
                Storage::disk('public')->delete($relativePath);
            }
        }
    }

    private function normalizePublicMediaUrl(?string $url): ?string
    {
        if (!$url || !is_string($url)) {
            return $url;
        }

        // Convert legacy /storage/... URLs to API-served media URLs.
        if (str_starts_with($url, '/storage/')) {
            $relativePath = ltrim(substr($url, strlen('/storage/')), '/');
            if ($relativePath !== '') {
                return '/api/v1/profile/media/' . $relativePath;
            }
        }

        return $url;
    }

    private function profilePayload(ClientProfile $profile): ClientProfile
    {
        $profile->profile_picture_url = $this->normalizePublicMediaUrl($profile->profile_picture_url);
        $profile->cover_photo_url = $this->normalizePublicMediaUrl($profile->cover_photo_url);
        return $profile;
    }

    private function getClientMediaLibrary(string $clientId): Collection
    {
        return WorkoutLog::where('client_id', $clientId)
            ->whereNotNull('workout_images')
            ->orderByDesc('workout_date')
            ->get()
            ->flatMap(function (WorkoutLog $log) {
                $images = is_array($log->workout_images) ? $log->workout_images : [];
                return collect($images);
            })
            ->filter(fn ($url) => is_string($url) && $url !== '')
            ->unique()
            ->values();
    }

    /**
     * Get authenticated client's profile
     */
    public function show(Request $request)
    {
        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            // Create empty profile if doesn't exist
            $profile = ClientProfile::create([
                'client_id' => $client->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'client' => [
                    'id' => $client->id,
                    'email' => $client->email,
                    'email_verified_at' => $client->email_verified_at,
                ],
                'profile' => $this->profilePayload($profile->load('client')),
                'goals' => $client->goals()->select('id', 'name', 'slug')->orderBy('name')->get(),
            ],
        ], 200);
    }

    /**
     * Serve profile/cover media stored on public disk.
     */
    public function media(string $path)
    {
        $normalizedPath = ltrim($path, '/');
        $allowed = str_starts_with($normalizedPath, 'profile-pictures/')
            || str_starts_with($normalizedPath, 'cover-photos/')
            || str_starts_with($normalizedPath, 'workout-photos/')
            || str_starts_with($normalizedPath, 'profile-badges/');
        if (!$allowed) {
            return response()->json([
                'success' => false,
                'message' => 'Media path not allowed.',
            ], 403);
        }

        if (!Storage::disk('public')->exists($normalizedPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Media not found.',
            ], 404);
        }

        $disk = Storage::disk('public');
        $absolutePath = $disk->path($normalizedPath);
        $mimeType = @mime_content_type($absolutePath) ?: 'application/octet-stream';

        return Response::stream(function () use ($normalizedPath, $disk) {
            echo $disk->get($normalizedPath);
        }, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Update client profile
     */
    public function update(Request $request)
    {
        $client = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'display_name' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:500',
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female,other,prefer_not_to_say',
            'height_cm' => 'nullable|integer|min:50|max:300',
            'current_weight_kg' => 'nullable|numeric|min:20|max:500',
            'target_weight_kg' => 'nullable|numeric|min:20|max:500',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:32',
            'street_address' => 'nullable|string|max:240',
            'barangay' => 'nullable|string|max:120',
            'timezone' => 'nullable|string|max:50',
            'activity_level' => 'nullable|in:sedentary,light,moderate,active,very_active',
            'experience_level' => 'nullable|in:beginner,intermediate,advanced,expert',
            'experience_running' => 'nullable|in:beginner,intermediate,advanced,expert',
            'experience_gym' => 'nullable|in:beginner,intermediate,advanced,expert',
            'experience_others_title' => 'nullable|string|max:100',
            'experience_others' => 'nullable|in:beginner,intermediate,advanced,expert',
            'primary_niche' => 'nullable|in:running,gym,biking,hybrid',
            'secondary_niches' => 'nullable|array|max:10',
            'secondary_niches.*' => 'string|max:50|distinct',
            'workout_preferences' => 'nullable|array',
            'nutrition_preferences' => 'nullable|array',
            'theme_mode' => 'nullable|in:light,dark',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile = $client->profile;

        if (!$profile) {
            $profile = ClientProfile::create([
                'client_id' => $client->id,
            ]);
        }

        $profile->update($request->only([
            'first_name',
            'last_name',
            'display_name',
            'bio',
            'date_of_birth',
            'gender',
            'height_cm',
            'current_weight_kg',
            'target_weight_kg',
            'city',
            'province',
            'country',
            'phone',
            'street_address',
            'barangay',
            'timezone',
            'activity_level',
            'experience_level',
            'experience_running',
            'experience_gym',
            'experience_others_title',
            'experience_others',
            'primary_niche',
            'secondary_niches',
            'workout_preferences',
            'nutrition_preferences',
            'theme_mode',
        ]));

        if ($request->hasAny(['experience_running', 'experience_gym', 'experience_others'])) {
            $profile->experience_level = $this->deriveOverallExperienceLevel(
                $profile->experience_running,
                $profile->experience_gym,
                $profile->experience_others
            );
            $profile->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'profile' => $profile->fresh(),
                'goals' => $client->goals()->select('id', 'name', 'slug')->orderBy('name')->get(),
            ],
        ], 200);
    }

    /**
     * Update selected fitness goals for authenticated client
     */
    public function updateGoals(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'goals' => 'required|array|min:1|max:3',
            'goals.*' => 'required|uuid|exists:goals,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = $request->user();
        $requestedGoalIds = $request->input('goals', []);
        $allowedGoalSlugs = ['lose-weight', 'build-muscle', 'improve-cardio', 'stay-active'];
        $allowedGoalIds = \App\Models\Goal::query()
            ->whereIn('id', $requestedGoalIds)
            ->whereIn('slug', $allowedGoalSlugs)
            ->pluck('id')
            ->all();

        if (count($allowedGoalIds) !== count($requestedGoalIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => [
                    'goals' => ['Please select valid fitness goals from onboarding options only.'],
                ],
            ], 422);
        }

        $client->goals()->sync($requestedGoalIds);

        return response()->json([
            'success' => true,
            'message' => 'Fitness goals updated successfully',
            'data' => [
                'goals' => $client->goals()->select('id', 'name', 'slug')->orderBy('name')->get(),
            ],
        ], 200);
    }

    /**
     * Upload profile picture
     */
    public function uploadPicture(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            $profile = ClientProfile::create(['client_id' => $client->id]);
        }

        $this->deletePublicFileByUrl($profile->profile_picture_url, ['profile-pictures']);
        $storedPath = $request->file('profile_picture')->store('profile-pictures', 'public');

        $profile->update([
            'profile_picture_url' => '/api/v1/profile/media/' . $storedPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile picture uploaded successfully',
            'data' => [
                'profile_picture_url' => $profile->profile_picture_url,
            ],
        ], 200);
    }

    /**
     * Upload profile cover photo
     */
    public function uploadCoverPhoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cover_photo' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:15360',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            $profile = ClientProfile::create(['client_id' => $client->id]);
        }

        $this->deletePublicFileByUrl($profile->cover_photo_url, ['cover-photos']);
        $storedPath = $request->file('cover_photo')->store('cover-photos', 'public');

        $profile->update([
            'cover_photo_url' => '/api/v1/profile/media/' . $storedPath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cover photo uploaded successfully',
            'data' => [
                'cover_photo_url' => $profile->cover_photo_url,
            ],
        ], 200);
    }

    /**
     * Remove profile picture
     */
    public function removePicture(Request $request)
    {
        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            $profile = ClientProfile::create(['client_id' => $client->id]);
        }

        $this->deletePublicFileByUrl($profile->profile_picture_url, ['profile-pictures']);
        $profile->update(['profile_picture_url' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Profile picture removed successfully',
            'data' => [
                'profile_picture_url' => null,
            ],
        ], 200);
    }

    /**
     * Remove cover photo
     */
    public function removeCoverPhoto(Request $request)
    {
        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            $profile = ClientProfile::create(['client_id' => $client->id]);
        }

        $this->deletePublicFileByUrl($profile->cover_photo_url, ['cover-photos']);
        $profile->update(['cover_photo_url' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Cover photo removed successfully',
            'data' => [
                'cover_photo_url' => null,
            ],
        ], 200);
    }

    /**
     * List media library from client's own workout logs.
     */
    public function mediaLibrary(Request $request)
    {
        $client = $request->user();
        $images = $this->getClientMediaLibrary($client->id);

        return response()->json([
            'success' => true,
            'data' => [
                'images' => $images,
            ],
        ], 200);
    }

    /**
     * Set profile picture from owned media library URL.
     */
    public function setPictureFromLibrary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image_url' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = $request->user();
        $imageUrl = $request->input('image_url');
        $images = $this->getClientMediaLibrary($client->id);

        if (!$images->contains($imageUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'The selected image does not belong to your media library.',
            ], 403);
        }

        $profile = $client->profile ?: ClientProfile::create(['client_id' => $client->id]);
        $this->deletePublicFileByUrl($profile->profile_picture_url, ['profile-pictures']);
        $profile->update(['profile_picture_url' => $imageUrl]);

        return response()->json([
            'success' => true,
            'message' => 'Profile picture updated successfully',
            'data' => [
                'profile_picture_url' => $profile->profile_picture_url,
            ],
        ], 200);
    }

    /**
     * Set cover photo from owned media library URL.
     */
    public function setCoverFromLibrary(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image_url' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = $request->user();
        $imageUrl = $request->input('image_url');
        $images = $this->getClientMediaLibrary($client->id);

        if (!$images->contains($imageUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'The selected image does not belong to your media library.',
            ], 403);
        }

        $profile = $client->profile ?: ClientProfile::create(['client_id' => $client->id]);
        $this->deletePublicFileByUrl($profile->cover_photo_url, ['cover-photos']);
        $profile->update(['cover_photo_url' => $imageUrl]);

        return response()->json([
            'success' => true,
            'message' => 'Cover photo updated successfully',
            'data' => [
                'cover_photo_url' => $profile->cover_photo_url,
            ],
        ], 200);
    }

    public function badges(Request $request)
    {
        if (!Schema::hasTable('client_badges')) {
            return response()->json([
                'success' => true,
                'data' => ['badges' => []],
            ], 200);
        }

        $client = $request->user();
        $badges = ClientBadge::query()
            ->where('client_id', $client->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ClientBadge $badge) {
                return [
                    'id' => $badge->id,
                    'label' => $badge->label,
                    'image_url' => $this->normalizePublicMediaUrl($badge->image_url),
                    'created_at' => $badge->created_at?->toISOString(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => ['badges' => $badges],
        ], 200);
    }

    public function transactions(Request $request)
    {
        $client = $request->user();

        if (! Schema::hasTable('client_admin_event_registrations') || ! Schema::hasTable('admin_events')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'transactions' => [],
                    'pagination' => [
                        'page' => 1,
                        'per_page' => 15,
                        'total' => 0,
                        'last_page' => 1,
                    ],
                ],
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $perPage = (int) $request->input('per_page', 15);

        $paginator = ClientAdminEventRegistration::query()
            ->where('client_id', $client->id)
            ->with(['event'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $transactions = $paginator->getCollection()
            ->map(fn (ClientAdminEventRegistration $registration) => $this->serializeTransaction($registration))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions,
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(ClientAdminEventRegistration $registration): array
    {
        $registration->loadMissing(['event']);

        /** @var AdminEvent|null $event */
        $event = $registration->event;

        $totalAmount = $registration->amount_snapshot !== null
            ? round((float) $registration->amount_snapshot, 2)
            : null;
        $deliveryFee = $registration->delivery_fee_snapshot !== null
            ? round((float) $registration->delivery_fee_snapshot, 2)
            : null;
        $registrationFee = null;
        if ($totalAmount !== null) {
            $registrationFee = round(max(0, $totalAmount - ($deliveryFee ?? 0)), 2);
        }

        $deliveryDetails = Schema::hasColumn('client_admin_event_registrations', 'delivery_details')
            && is_array($registration->delivery_details)
            ? $registration->delivery_details
            : null;

        return [
            'id' => (string) $registration->id,
            'description' => $event?->description ? (string) $event->description : null,
            'event' => [
                'id' => $event ? (string) $event->id : null,
                'title' => $event ? (string) $event->title : 'Event',
                'description' => $event?->description ? (string) $event->description : null,
                'category' => $event?->category,
                'location' => $event?->location,
                'image_url' => $event?->image_url ? (string) $event->image_url : null,
                'starts_at' => $event?->starts_at?->toISOString(),
                'ends_at' => $event?->ends_at?->toISOString(),
            ],
            'registration_status' => (string) ($registration->registration_status ?? ''),
            'payment_status' => (string) ($registration->payment_status ?? ''),
            'registration_fee' => $registrationFee,
            'amount' => $registrationFee,
            'delivery_fee' => $deliveryFee,
            'total_amount' => $totalAmount,
            'currency' => 'PHP',
            'paymaya_rrn' => $registration->paymaya_rrn,
            'paymaya_checkout_id' => $registration->paymaya_checkout_id,
            'paymaya_payment_status_snapshot' => Schema::hasColumn('client_admin_event_registrations', 'paymaya_payment_status_snapshot')
                ? $registration->paymaya_payment_status_snapshot
                : null,
            'payment_method' => Schema::hasColumn('client_admin_event_registrations', 'payment_method')
                ? $registration->payment_method
                : null,
            'manual_payment_reference' => Schema::hasColumn('client_admin_event_registrations', 'manual_payment_reference')
                ? $registration->manual_payment_reference
                : null,
            'paid_at' => Schema::hasColumn('client_admin_event_registrations', 'paid_at')
                ? $registration->paid_at?->toISOString()
                : null,
            'delivery_details' => $deliveryDetails,
            'created_at' => $registration->created_at?->toISOString(),
            'updated_at' => $registration->updated_at?->toISOString(),
        ];
    }
}
