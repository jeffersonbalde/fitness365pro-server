<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminEvent;
use App\Models\EventProgressSubmission;
use App\Models\WorkoutLog;
use App\Services\EventProgressSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AdminEventProgressController extends Controller
{
    public function index(Request $request)
    {
        if (! EventProgressSubmissionService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Progress queue not migrated.'], 503);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:pending,approved,rejected,all',
            'admin_event_id' => 'nullable|uuid',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $status = (string) $request->input('status', 'pending');
        $q = EventProgressSubmission::query()
            ->with(['client.profile', 'event:id,title', 'workoutLog'])
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($request->filled('admin_event_id')) {
            $q->where('admin_event_id', $request->input('admin_event_id'));
        }

        $paginator = $q->paginate((int) $request->input('per_page', 20));
        $paginator->getCollection()->transform(fn (EventProgressSubmission $s) => $this->serializeSubmission($s));

        return response()->json(['success' => true, 'data' => $paginator]);
    }

    public function approve(Request $request, string $id)
    {
        if (! EventProgressSubmissionService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Progress queue not migrated.'], 503);
        }

        $sub = EventProgressSubmission::query()->find($id);
        if (! $sub) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($sub->status !== EventProgressSubmission::STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'This submission is not pending.'], 422);
        }

        EventProgressSubmissionService::approve($sub, (string) $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => 'Progress approved and applied.',
            'data' => ['submission' => $this->serializeSubmission($sub->fresh(['client.profile', 'event', 'workoutLog']))],
        ]);
    }

    public function reject(Request $request, string $id)
    {
        if (! EventProgressSubmissionService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Progress queue not migrated.'], 503);
        }

        $request->merge(['note' => trim((string) $request->input('note', ''))]);

        $validator = Validator::make($request->all(), [
            'note' => 'required|string|min:3|max:600',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $sub = EventProgressSubmission::query()->find($id);
        if (! $sub) {
            return response()->json(['success' => false, 'message' => 'Submission not found.'], 404);
        }
        if ($sub->status !== EventProgressSubmission::STATUS_PENDING) {
            return response()->json(['success' => false, 'message' => 'This submission is not pending.'], 422);
        }

        $note = (string) $request->input('note');
        EventProgressSubmissionService::reject($sub, (string) $request->user()->id, $note);

        return response()->json([
            'success' => true,
            'message' => 'Submission rejected.',
            'data' => ['submission' => $this->serializeSubmission($sub->fresh(['client.profile', 'event', 'workoutLog']))],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSubmission(EventProgressSubmission $s): array
    {
        $s->loadMissing(['client.profile', 'event', 'workoutLog']);

        $client = $s->client;
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

        $workout = null;
        if ($s->workout_log_id && Schema::hasTable('workout_logs')) {
            $wl = $s->workoutLog ?? WorkoutLog::query()->find($s->workout_log_id);
            if ($wl) {
                $workout = $this->serializeLinkedWorkout($wl);
            }
        }

        $event = $s->event;
        if (! $event && $s->admin_event_id) {
            $event = AdminEvent::query()->select('id', 'title')->find($s->admin_event_id);
        }

        return [
            'id' => (string) $s->id,
            'status' => (string) $s->status,
            'source' => (string) $s->source,
            'workout_log_id' => $s->workout_log_id ? (string) $s->workout_log_id : null,
            'distance_delta_km' => (float) $s->distance_delta_km,
            'pace_min_per_km' => $s->pace_min_per_km !== null ? (float) $s->pace_min_per_km : null,
            'client' => [
                'id' => $client ? (string) $client->id : null,
                'display_name' => $displayName !== '' ? $displayName : 'Member',
                'email' => $client?->email,
            ],
            'event' => $event ? ['id' => (string) $event->id, 'title' => (string) $event->title] : null,
            'workout' => $workout,
            'review_note' => $s->review_note,
            'reviewed_at' => $s->reviewed_at?->toISOString(),
            'created_at' => $s->created_at?->toISOString(),
            'updated_at' => $s->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeLinkedWorkout(WorkoutLog $wl): array
    {
        $imgs = [];
        $rawImages = $wl->workout_images ?? null;
        if (is_array($rawImages)) {
            foreach ($rawImages as $u) {
                $abs = $this->normalizePublicAssetUrl(is_string($u) ? $u : '');
                if ($abs !== '') {
                    $imgs[] = $abs;
                }
            }
        }

        $row = [
            'id' => (string) $wl->id,
            'workout_type' => (string) ($wl->workout_type ?? ''),
            'workout_date' => $wl->workout_date?->toDateString(),
            'distance_km' => $wl->distance_km !== null ? (float) $wl->distance_km : null,
            'duration_minutes' => $wl->duration_minutes !== null ? (int) $wl->duration_minutes : null,
            'duration_seconds' => $wl->duration_seconds !== null ? (int) $wl->duration_seconds : null,
            'pace_min_per_km' => $wl->pace_min_per_km !== null ? (float) $wl->pace_min_per_km : null,
            'status' => (string) ($wl->status ?? ''),
            'notes' => $wl->notes,
            'caption' => Schema::hasColumn($wl->getTable(), 'caption') ? $wl->caption : null,
            'location' => Schema::hasColumn($wl->getTable(), 'location') ? $wl->location : null,
            'entry_type' => Schema::hasColumn($wl->getTable(), 'entry_type') ? (string) ($wl->entry_type ?? 'workout') : 'workout',
            'plan_day' => $wl->plan_day !== null ? (int) $wl->plan_day : null,
            'admin_event_id' => Schema::hasColumn($wl->getTable(), 'admin_event_id') && $wl->admin_event_id
                ? (string) $wl->admin_event_id
                : null,
            'challenge_progress_approved_km' => Schema::hasColumn($wl->getTable(), 'challenge_progress_approved_km') && $wl->challenge_progress_approved_km !== null
                ? (float) $wl->challenge_progress_approved_km
                : null,
            'workout_images' => $imgs,
        ];

        return $row;
    }

    /** Turn relative storage URLs (/storage/...) into absolute URLs for admin clients that run on a separate origin. */
    protected function normalizePublicAssetUrl(string $urlOrPath): string
    {
        $s = trim($urlOrPath);
        if ($s === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $s)) {
            return $s;
        }
        $base = rtrim((string) config('app.url'), '/');
        if ($s[0] === '/') {
            return $base.$s;
        }

        return $base.'/'.$s;
    }
}
