<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Services\ClientNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        if (! ClientNotificationService::tableReady()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'items' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                    ],
                ],
            ]);
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

        $client = $request->user();
        $perPage = (int) $request->input('per_page', 20);

        $paginator = ClientNotification::query()
            ->where('recipient_client_id', $client->id)
            ->with(['actor.profile'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = $paginator->getCollection()
            ->map(fn (ClientNotification $notification) => $this->serializeNotification($notification))
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
        ]);
    }

    public function unreadCount(Request $request)
    {
        if (! ClientNotificationService::tableReady()) {
            return response()->json([
                'success' => true,
                'data' => ['unread_count' => 0],
            ]);
        }

        $client = $request->user();
        $count = ClientNotification::query()
            ->where('recipient_client_id', $client->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => $count],
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        if (! ClientNotificationService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Notifications not available.'], 503);
        }

        $client = $request->user();
        $notification = ClientNotification::query()
            ->where('recipient_client_id', $client->id)
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'notification' => $this->serializeNotification($notification->fresh(['actor.profile'])),
                'unread_count' => ClientNotification::query()
                    ->where('recipient_client_id', $client->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markAllRead(Request $request)
    {
        if (! ClientNotificationService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Notifications not available.'], 503);
        }

        $client = $request->user();

        ClientNotification::query()
            ->where('recipient_client_id', $client->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => ['unread_count' => 0],
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        if (! ClientNotificationService::tableReady()) {
            return response()->json(['success' => false, 'message' => 'Notifications not available.'], 503);
        }

        $client = $request->user();
        $notification = ClientNotification::query()
            ->where('recipient_client_id', $client->id)
            ->where('id', $id)
            ->first();

        if (! $notification) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $wasUnread = ! $notification->read_at;
        $notification->delete();

        $unreadCount = $wasUnread
            ? ClientNotification::query()
                ->where('recipient_client_id', $client->id)
                ->whereNull('read_at')
                ->count()
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => $unreadCount ?? ClientNotification::query()
                    ->where('recipient_client_id', $client->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeNotification(ClientNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $actor = $notification->actor;

        return [
            'id' => (string) $notification->id,
            'type' => (string) $notification->type,
            'title' => (string) $notification->title,
            'message' => (string) $notification->message,
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'link' => $this->resolveLink($notification, $data),
            'meta' => $data,
            'actor' => $actor ? $this->serializeActor($actor) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveLink(ClientNotification $notification, array $data): ?string
    {
        $type = (string) $notification->type;

        if (in_array($type, [ClientNotification::TYPE_LOGIN, ClientNotification::TYPE_LOGOUT], true)) {
            return null;
        }

        if ($type === ClientNotification::TYPE_NEW_FOLLOWER && $notification->actor_client_id) {
            return '/profile/'.(string) $notification->actor_client_id;
        }

        if (in_array($type, [ClientNotification::TYPE_PROGRESS_APPROVED, ClientNotification::TYPE_PROGRESS_REJECTED], true)) {
            $eventId = $data['admin_event_id'] ?? null;

            return $eventId ? '/challenges/'.(string) $eventId : null;
        }

        if (isset($data['workout_log_id'])) {
            return '/profile';
        }

        $stored = $data['link'] ?? null;
        if (is_string($stored) && $stored !== '' && ! in_array($stored, ['/settings', '/login'], true)) {
            return $stored;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeActor(Client $actor): array
    {
        $profile = $actor->profile;

        return [
            'id' => (string) $actor->id,
            'display_name' => ClientNotificationService::displayName($actor),
            'profile_picture_url' => $profile?->profile_picture_url,
        ];
    }
}
