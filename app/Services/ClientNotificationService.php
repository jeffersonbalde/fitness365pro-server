<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientNotification;
use App\Models\EventProgressSubmission;
use App\Models\WorkoutComment;
use App\Models\WorkoutLog;
use Illuminate\Support\Facades\Schema;

class ClientNotificationService
{
    public static function tableReady(): bool
    {
        return Schema::hasTable('client_notifications');
    }

    public static function displayName(?Client $client): string
    {
        if (! $client) {
            return 'Someone';
        }

        $profile = $client->profile;
        $displayName = $profile?->display_name
            ?: trim((string) (($profile?->first_name ?? '').' '.($profile?->last_name ?? '')));

        if ($displayName === '') {
            $displayName = explode('@', (string) $client->email)[0] ?? 'Member';
        }

        return $displayName !== '' ? $displayName : 'Member';
    }

    public static function workoutLiked(Client $actor, WorkoutLog $workout): void
    {
        if (! static::tableReady()) {
            return;
        }

        $ownerId = (string) $workout->client_id;
        if ($ownerId === (string) $actor->id) {
            return;
        }

        $name = static::displayName($actor);

        static::createNotification(
            recipientClientId: $ownerId,
            actorClientId: (string) $actor->id,
            type: ClientNotification::TYPE_WORKOUT_LIKED,
            title: 'New like',
            message: "{$name} liked your post.",
            data: [
                'workout_log_id' => (string) $workout->id,
                'link' => '/profile',
            ],
        );
    }

    public static function workoutCommented(Client $actor, WorkoutLog $workout, WorkoutComment $comment): void
    {
        if (! static::tableReady()) {
            return;
        }

        $name = static::displayName($actor);
        $actorId = (string) $actor->id;
        $ownerId = (string) $workout->client_id;

        if ($comment->parent_comment_id) {
            $parent = WorkoutComment::query()->find($comment->parent_comment_id);
            $parentOwnerId = $parent ? (string) $parent->client_id : null;

            if ($parent && $parentOwnerId !== $actorId) {
                static::createNotification(
                    recipientClientId: $parentOwnerId,
                    actorClientId: $actorId,
                    type: ClientNotification::TYPE_COMMENT_REPLIED,
                    title: 'New reply',
                    message: "{$name} replied to your comment.",
                    data: [
                        'workout_log_id' => (string) $workout->id,
                        'comment_id' => (string) $comment->id,
                        'parent_comment_id' => (string) $parent->id,
                        'link' => '/profile',
                    ],
                );
            }

            if ($ownerId !== $actorId && $ownerId !== $parentOwnerId) {
                static::createNotification(
                    recipientClientId: $ownerId,
                    actorClientId: $actorId,
                    type: ClientNotification::TYPE_WORKOUT_COMMENTED,
                    title: 'New comment on your post',
                    message: "{$name} commented on your post.",
                    data: [
                        'workout_log_id' => (string) $workout->id,
                        'comment_id' => (string) $comment->id,
                        'link' => '/profile',
                    ],
                );
            }

            return;
        }

        if ($ownerId !== $actorId) {
            static::createNotification(
                recipientClientId: $ownerId,
                actorClientId: $actorId,
                type: ClientNotification::TYPE_WORKOUT_COMMENTED,
                title: 'New comment',
                message: "{$name} commented on your post.",
                data: [
                    'workout_log_id' => (string) $workout->id,
                    'comment_id' => (string) $comment->id,
                    'link' => '/profile',
                ],
            );
        }
    }

    public static function commentLiked(Client $actor, WorkoutComment $comment): void
    {
        if (! static::tableReady()) {
            return;
        }

        $ownerId = (string) $comment->client_id;
        if ($ownerId === (string) $actor->id) {
            return;
        }

        $name = static::displayName($actor);

        static::createNotification(
            recipientClientId: $ownerId,
            actorClientId: (string) $actor->id,
            type: ClientNotification::TYPE_COMMENT_LIKED,
            title: 'Comment liked',
            message: "{$name} liked your comment.",
            data: [
                'workout_log_id' => (string) $comment->workout_log_id,
                'comment_id' => (string) $comment->id,
                'link' => '/profile',
            ],
        );
    }

    public static function newFollower(Client $actor, Client $followed): void
    {
        if (! static::tableReady()) {
            return;
        }

        if ((string) $actor->id === (string) $followed->id) {
            return;
        }

        $name = static::displayName($actor);

        static::createNotification(
            recipientClientId: (string) $followed->id,
            actorClientId: (string) $actor->id,
            type: ClientNotification::TYPE_NEW_FOLLOWER,
            title: 'New follower',
            message: "{$name} started following you.",
            data: [
                'link' => '/profile/'.(string) $actor->id,
            ],
        );
    }

    public static function login(Client $client): void
    {
        if (! static::tableReady()) {
            return;
        }

        static::createNotification(
            recipientClientId: (string) $client->id,
            actorClientId: null,
            type: ClientNotification::TYPE_LOGIN,
            title: 'Signed in',
            message: 'You signed in to your Fitness 365 Pro account.',
            data: [],
        );
    }

    public static function logout(Client $client): void
    {
        if (! static::tableReady()) {
            return;
        }

        static::createNotification(
            recipientClientId: (string) $client->id,
            actorClientId: null,
            type: ClientNotification::TYPE_LOGOUT,
            title: 'Signed out',
            message: 'You signed out of your Fitness 365 Pro account.',
            data: [],
        );
    }

    public static function progressApproved(EventProgressSubmission $submission): void
    {
        if (! static::tableReady()) {
            return;
        }

        $eventTitle = static::eventTitle($submission);
        $km = number_format((float) $submission->distance_delta_km, 2);

        static::createNotification(
            recipientClientId: (string) $submission->client_id,
            actorClientId: null,
            type: ClientNotification::TYPE_PROGRESS_APPROVED,
            title: 'Progress approved',
            message: "Your {$km} km progress for \"{$eventTitle}\" was approved.",
            data: [
                'submission_id' => (string) $submission->id,
                'admin_event_id' => (string) $submission->admin_event_id,
                'workout_log_id' => $submission->workout_log_id ? (string) $submission->workout_log_id : null,
                'link' => '/challenges/'.(string) $submission->admin_event_id,
            ],
        );
    }

    public static function progressRejected(EventProgressSubmission $submission, string $note): void
    {
        if (! static::tableReady()) {
            return;
        }

        $eventTitle = static::eventTitle($submission);
        $preview = mb_strlen($note) > 120 ? mb_substr($note, 0, 117).'...' : $note;

        static::createNotification(
            recipientClientId: (string) $submission->client_id,
            actorClientId: null,
            type: ClientNotification::TYPE_PROGRESS_REJECTED,
            title: 'Progress rejected',
            message: "Your progress for \"{$eventTitle}\" was rejected. {$preview}",
            data: [
                'submission_id' => (string) $submission->id,
                'admin_event_id' => (string) $submission->admin_event_id,
                'workout_log_id' => $submission->workout_log_id ? (string) $submission->workout_log_id : null,
                'review_note' => $note,
                'link' => '/challenges/'.(string) $submission->admin_event_id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function createNotification(
        string $recipientClientId,
        ?string $actorClientId,
        string $type,
        string $title,
        string $message,
        array $data = [],
    ): ?ClientNotification {
        if (! static::tableReady()) {
            return null;
        }

        return ClientNotification::query()->create([
            'recipient_client_id' => $recipientClientId,
            'actor_client_id' => $actorClientId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected static function eventTitle(EventProgressSubmission $submission): string
    {
        if ($submission->relationLoaded('event') && $submission->event) {
            return (string) $submission->event->title;
        }

        $event = AdminEvent::query()->select('id', 'title')->find($submission->admin_event_id);

        return $event ? (string) $event->title : 'Challenge';
    }

    public static function eventRegisteredManually(Client $client, AdminEvent $event, string $paymentMethod): void
    {
        if (! static::tableReady()) {
            return;
        }

        $title = (string) $event->title;
        $methodLabel = match ($paymentMethod) {
            'cash' => 'cash',
            'office' => 'office',
            'bank_transfer' => 'bank transfer',
            'free' => 'complimentary',
            default => 'manual',
        };

        static::createNotification(
            recipientClientId: (string) $client->id,
            actorClientId: null,
            type: ClientNotification::TYPE_EVENT_REGISTERED,
            title: 'Event registration confirmed',
            message: "You have been registered for {$title} ({$methodLabel} payment).",
            data: [
                'admin_event_id' => (string) $event->id,
                'payment_method' => $paymentMethod,
                'link' => '/challenges/'.(string) $event->id,
            ],
        );
    }
}
