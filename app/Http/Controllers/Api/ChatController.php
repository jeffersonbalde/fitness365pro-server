<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ChatBlock;
use App\Models\ChatReport;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    private function isBlockedPair(string $clientIdA, string $clientIdB): bool
    {
        return ChatBlock::where(function ($query) use ($clientIdA, $clientIdB) {
            $query->where('blocker_client_id', $clientIdA)
                ->where('blocked_client_id', $clientIdB);
        })->orWhere(function ($query) use ($clientIdA, $clientIdB) {
            $query->where('blocker_client_id', $clientIdB)
                ->where('blocked_client_id', $clientIdA);
        })->exists();
    }

    private function ensureNotBlockedForConversation(Conversation $conversation, string $viewerId): bool
    {
        if ($conversation->type !== 'direct') {
            return true;
        }

        $otherIds = $conversation->members()
            ->where('status', 'active')
            ->where('client_id', '!=', $viewerId)
            ->pluck('client_id');

        foreach ($otherIds as $otherId) {
            if ($this->isBlockedPair($viewerId, (string) $otherId)) {
                return false;
            }
        }

        return true;
    }

    private function enforceSendRateLimit(string $viewerId, string $scope): ?array
    {
        $maxAttempts = max(1, (int) config('chat.send_rate_limit_per_minute', 30));
        $decaySeconds = 60;
        $key = "chat-send:{$scope}:{$viewerId}";
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return [
                'retry_after' => RateLimiter::availableIn($key),
                'max_attempts' => $maxAttempts,
            ];
        }

        RateLimiter::hit($key, $decaySeconds);
        return null;
    }

    private function hasBlockedTerms(string $content): bool
    {
        $terms = config('chat.moderation.blocked_terms', []);
        if (!is_array($terms) || empty($terms)) {
            return false;
        }

        $normalized = mb_strtolower($content);
        foreach ($terms as $term) {
            $needle = mb_strtolower((string) $term);
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function retentionCutoff()
    {
        $days = max(1, (int) config('chat.retention_days', 365));
        return now()->subDays($days);
    }

    private function getCommunityMembership(string $communityId, string $viewerId): ?CommunityMember
    {
        return CommunityMember::where('community_id', $communityId)
            ->where('client_id', $viewerId)
            ->first();
    }

    private function canAccessCommunityChat(Community $community, Client $viewer): bool
    {
        if ($community->owner_client_id === $viewer->id) {
            return true;
        }

        $membership = $this->getCommunityMembership($community->id, $viewer->id);
        return $membership && $membership->status === 'active';
    }

    private function canModerateCommunityChat(Community $community, Client $viewer): bool
    {
        if ($community->owner_client_id === $viewer->id) {
            return true;
        }
        $membership = $this->getCommunityMembership($community->id, $viewer->id);
        return $membership
            && $membership->status === 'active'
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    private function mapClientSummary(Client $client): array
    {
        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? ''));

        if (!$displayName) {
            $displayName = explode('@', $client->email)[0] ?? 'User';
        }

        return [
            'id' => $client->id,
            'display_name' => $displayName,
            'profile_picture_url' => $profile?->profile_picture_url,
        ];
    }

    private function getActiveMembership(string $conversationId, string $viewerId): ?ConversationMember
    {
        return ConversationMember::where('conversation_id', $conversationId)
            ->where('client_id', $viewerId)
            ->where('status', 'active')
            ->first();
    }

    private function canAccessConversation(string $conversationId, string $viewerId): bool
    {
        return $this->getActiveMembership($conversationId, $viewerId) !== null;
    }

    private function mapMessage(Message $message, Client $viewer): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'message_type' => $message->message_type,
            'body' => $message->body,
            'attachments' => $message->attachments ?? [],
            'delivery_status' => $message->delivery_status ?? 'sent',
            'delivered_at' => $message->delivered_at,
            'read_at' => $message->read_at,
            'sender' => $message->sender ? $this->mapClientSummary($message->sender) : null,
            'is_mine' => $message->sender_client_id === $viewer->id,
            'created_at' => $message->created_at,
            'updated_at' => $message->updated_at,
        ];
    }

    private function ensureGeneralCommunityConversation(Community $community, Client $viewer): Conversation
    {
        $conversation = Conversation::where('type', 'community_channel')
            ->where('community_id', $community->id)
            ->where('channel_name', 'general')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return Conversation::create([
            'type' => 'community_channel',
            'community_id' => $community->id,
            'channel_name' => 'general',
            'created_by_client_id' => $viewer->id,
            'is_active' => true,
        ]);
    }

    public function conversations(Request $request)
    {
        $viewer = $request->user();

        $memberships = ConversationMember::query()
            ->where('client_id', $viewer->id)
            ->where('status', 'active')
            ->with([
                'conversation.community',
                'conversation.members.client.profile',
            ])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $conversationIds = $memberships->pluck('conversation_id')->filter()->values();
        $lastMessageByConversation = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('conversation_id')
            ->map(fn ($items) => $items->first());

        $conversations = $memberships->map(function (ConversationMember $membership) use ($viewer, $lastMessageByConversation) {
            $conversation = $membership->conversation;
            if (!$conversation || !$conversation->is_active) {
                return null;
            }
            if (!$this->ensureNotBlockedForConversation($conversation, $viewer->id)) {
                return null;
            }

            $otherMembers = $conversation->members
                ->where('status', 'active')
                ->where('client_id', '!=', $viewer->id)
                ->pluck('client')
                ->filter()
                ->map(fn (Client $client) => $this->mapClientSummary($client))
                ->values();

            $unreadQuery = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_client_id', '!=', $viewer->id)
                ->whereNull('deleted_at');
            if ($membership->last_read_at) {
                $unreadQuery->where('created_at', '>', $membership->last_read_at);
            }
            $unreadCount = $unreadQuery->count();

            $lastMessage = $lastMessageByConversation->get($conversation->id);

            return [
                'id' => $conversation->id,
                'type' => $conversation->type,
                'community_id' => $conversation->community_id,
                'channel_name' => $conversation->channel_name,
                'community_name' => $conversation->community?->name,
                'other_members' => $otherMembers,
                'last_read_at' => $membership->last_read_at,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage ? $this->mapMessage($lastMessage->loadMissing('sender.profile'), $viewer) : null,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ];
        })->filter()->values();

        return response()->json([
            'success' => true,
            'data' => [
                'conversations' => $conversations,
                'total' => $conversations->count(),
            ],
        ], 200);
    }

    public function messages(Request $request, string $conversationId)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'after' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$this->canAccessConversation($conversationId, $viewer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this conversation.',
            ], 403);
        }

        $conversation = Conversation::with('members')->find($conversationId);
        if (!$conversation || !$this->ensureNotBlockedForConversation($conversation, $viewer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation is unavailable due to user safety settings.',
            ], 403);
        }

        $perPage = (int) $request->input('per_page', 40);
        $query = Message::query()
            ->where('conversation_id', $conversationId)
            ->with('sender.profile')
            ->where('created_at', '>=', $this->retentionCutoff())
            ->orderByDesc('created_at');

        if ($request->filled('after')) {
            $query->where('created_at', '>', $request->date('after'));
        }

        $messages = $query->limit($perPage)->get()->reverse()->values();

        // Polling baseline: when recipient fetches unseen messages, mark as delivered.
        Message::where('conversation_id', $conversationId)
            ->where('sender_client_id', '!=', $viewer->id)
            ->where('delivery_status', 'sent')
            ->update([
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'messages' => $messages->map(fn (Message $message) => $this->mapMessage($message, $viewer))->values(),
                'server_time' => now(),
            ],
        ], 200);
    }

    public function sendMessage(Request $request, string $conversationId)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'body' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$this->canAccessConversation($conversationId, $viewer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this conversation.',
            ], 403);
        }

        $conversation = Conversation::with('members')->find($conversationId);
        if (!$conversation || !$this->ensureNotBlockedForConversation($conversation, $viewer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation is unavailable due to user safety settings.',
            ], 403);
        }

        $body = trim((string) $request->input('body', ''));
        $attachments = $request->input('attachments', []);
        if ($body === '' && empty($attachments)) {
            return response()->json([
                'success' => false,
                'message' => 'Message body or attachments are required.',
            ], 422);
        }
        if ($body !== '' && $this->hasBlockedTerms($body)) {
            return response()->json([
                'success' => false,
                'message' => 'Message violates chat safety policy.',
            ], 422);
        }

        $rate = $this->enforceSendRateLimit($viewer->id, "direct:{$conversationId}");
        if ($rate) {
            return response()->json([
                'success' => false,
                'message' => 'Too many messages. Please slow down.',
                'data' => $rate,
            ], 429);
        }

        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_client_id' => $viewer->id,
            'message_type' => 'text',
            'body' => $body === '' ? null : $body,
            'attachments' => $attachments,
            'delivery_status' => 'sent',
        ])->load('sender.profile');

        Conversation::where('id', $conversationId)->update(['updated_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'message' => $this->mapMessage($message, $viewer),
            ],
        ], 201);
    }

    public function markRead(Request $request, string $conversationId)
    {
        $viewer = $request->user();
        $membership = $this->getActiveMembership($conversationId, $viewer->id);
        if (!$membership) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this conversation.',
            ], 403);
        }

        $readAt = now();
        $membership->last_read_at = $readAt;
        $membership->save();

        Message::where('conversation_id', $conversationId)
            ->where('sender_client_id', '!=', $viewer->id)
            ->whereIn('delivery_status', ['sent', 'delivered'])
            ->update([
                'delivery_status' => 'read',
                'read_at' => $readAt,
                'delivered_at' => $readAt,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Conversation marked as read.',
            'data' => [
                'last_read_at' => $membership->last_read_at,
            ],
        ], 200);
    }

    public function startDirect(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid|exists:clients,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $targetId = (string) $request->input('client_id');
        if ($viewer->id === $targetId) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot start a direct chat with yourself.',
            ], 422);
        }

        $target = Client::with('profile')->find($targetId);
        if (!$target) {
            return response()->json([
                'success' => false,
                'message' => 'Target user not found.',
            ], 404);
        }

        $viewerFollowsTarget = $viewer->following()->where('clients.id', $targetId)->exists();
        $targetFollowsViewer = $target->following()->where('clients.id', $viewer->id)->exists();
        if (!$viewerFollowsTarget && !$targetFollowsViewer) {
            return response()->json([
                'success' => false,
                'message' => 'You can only message users with an existing follow connection.',
            ], 403);
        }
        if ($this->isBlockedPair($viewer->id, $targetId)) {
            return response()->json([
                'success' => false,
                'message' => 'Direct chat is unavailable due to user safety settings.',
            ], 403);
        }

        $conversation = Conversation::query()
            ->where('type', 'direct')
            ->where('is_active', true)
            ->whereHas('members', fn ($q) => $q
                ->where('client_id', $viewer->id)
                ->where('status', 'active'))
            ->whereHas('members', fn ($q) => $q
                ->where('client_id', $targetId)
                ->where('status', 'active'))
            ->first();

        if (!$conversation) {
            $conversation = Conversation::create([
                'type' => 'direct',
                'created_by_client_id' => $viewer->id,
                'is_active' => true,
            ]);

            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'client_id' => $viewer->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'client_id' => $targetId,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'other_user' => $this->mapClientSummary($target),
                ],
            ],
        ], 200);
    }

    public function communityChannel(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if (!$this->canAccessCommunityChat($community, $viewer)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active community members can access chat.',
            ], 403);
        }

        $conversation = $this->ensureGeneralCommunityConversation($community, $viewer);

        return response()->json([
            'success' => true,
            'data' => [
                'channel' => [
                    'id' => $conversation->id,
                    'type' => $conversation->type,
                    'channel_name' => $conversation->channel_name,
                    'community_id' => $community->id,
                    'community_name' => $community->name,
                ],
            ],
        ], 200);
    }

    public function communityMessages(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if (!$this->canAccessCommunityChat($community, $viewer)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active community members can access chat.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'after' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $conversation = $this->ensureGeneralCommunityConversation($community, $viewer);
        $perPage = (int) $request->input('per_page', 40);
        $query = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('sender.profile')
            ->where('created_at', '>=', $this->retentionCutoff())
            ->orderByDesc('created_at');
        if ($request->filled('after')) {
            $query->where('created_at', '>', $request->date('after'));
        }

        $messages = $query->limit($perPage)->get()->reverse()->values();

        Message::where('conversation_id', $conversation->id)
            ->where('sender_client_id', '!=', $viewer->id)
            ->where('delivery_status', 'sent')
            ->update([
                'delivery_status' => 'delivered',
                'delivered_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'channel_id' => $conversation->id,
                'messages' => $messages->map(fn (Message $message) => $this->mapMessage($message, $viewer))->values(),
                'server_time' => now(),
            ],
        ], 200);
    }

    public function sendCommunityMessage(Request $request, string $communityId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if (!$this->canAccessCommunityChat($community, $viewer)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active community members can send chat messages.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $body = trim((string) $request->input('body', ''));
        $attachments = $request->input('attachments', []);
        if ($body === '' && empty($attachments)) {
            return response()->json([
                'success' => false,
                'message' => 'Message body or attachments are required.',
            ], 422);
        }
        if ($body !== '' && $this->hasBlockedTerms($body)) {
            return response()->json([
                'success' => false,
                'message' => 'Message violates chat safety policy.',
            ], 422);
        }

        $rate = $this->enforceSendRateLimit($viewer->id, "community:{$community->id}");
        if ($rate) {
            return response()->json([
                'success' => false,
                'message' => 'Too many messages. Please slow down.',
                'data' => $rate,
            ], 429);
        }

        $conversation = $this->ensureGeneralCommunityConversation($community, $viewer);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_client_id' => $viewer->id,
            'message_type' => 'text',
            'body' => $body === '' ? null : $body,
            'attachments' => $attachments,
            'delivery_status' => 'sent',
        ])->load('sender.profile');

        $conversation->updated_at = now();
        $conversation->save();

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'message' => $this->mapMessage($message, $viewer),
            ],
        ], 201);
    }

    public function deleteCommunityMessage(Request $request, string $communityId, string $messageId)
    {
        $viewer = $request->user();
        $community = Community::find($communityId);
        if (!$community || !$community->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found.',
            ], 404);
        }

        if (!$this->canAccessCommunityChat($community, $viewer)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active community members can access chat.',
            ], 403);
        }

        $conversation = $this->ensureGeneralCommunityConversation($community, $viewer);
        $message = Message::where('conversation_id', $conversation->id)->find($messageId);
        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        $canDelete = $message->sender_client_id === $viewer->id || $this->canModerateCommunityChat($community, $viewer);
        if (!$canDelete) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this message.',
            ], 403);
        }

        $message->delete();
        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully.',
        ], 200);
    }

    public function blockClient(Request $request)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|uuid|exists:clients,id',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $targetId = (string) $request->input('client_id');
        if ($targetId === $viewer->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot block yourself.',
            ], 422);
        }

        ChatBlock::updateOrCreate(
            ['blocker_client_id' => $viewer->id, 'blocked_client_id' => $targetId],
            ['reason' => $request->input('reason')]
        );

        return response()->json([
            'success' => true,
            'message' => 'User blocked successfully.',
        ], 200);
    }

    public function unblockClient(Request $request, string $clientId)
    {
        $viewer = $request->user();
        ChatBlock::where('blocker_client_id', $viewer->id)
            ->where('blocked_client_id', $clientId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'User unblocked successfully.',
        ], 200);
    }

    public function reportMessage(Request $request, string $messageId)
    {
        $viewer = $request->user();
        $validator = Validator::make($request->all(), [
            'reason' => 'required|in:spam,harassment,hate_speech,unsafe_content,other',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $message = Message::find($messageId);
        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found.',
            ], 404);
        }

        if (!$this->canAccessConversation($message->conversation_id, $viewer->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this message.',
            ], 403);
        }

        ChatReport::create([
            'reporter_client_id' => $viewer->id,
            'message_id' => $message->id,
            'reason' => $request->input('reason'),
            'notes' => $request->input('notes'),
            'status' => 'open',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message reported successfully.',
        ], 201);
    }
}

