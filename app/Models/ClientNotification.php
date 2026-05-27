<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientNotification extends Model
{
    use HasUuids;

    public const TYPE_WORKOUT_LIKED = 'workout_liked';

    public const TYPE_WORKOUT_COMMENTED = 'workout_commented';

    public const TYPE_COMMENT_REPLIED = 'comment_replied';

    public const TYPE_COMMENT_LIKED = 'comment_liked';

    public const TYPE_NEW_FOLLOWER = 'new_follower';

    public const TYPE_LOGIN = 'login';

    public const TYPE_LOGOUT = 'logout';

    public const TYPE_PROGRESS_APPROVED = 'progress_approved';

    public const TYPE_PROGRESS_REJECTED = 'progress_rejected';

    public const TYPE_EVENT_REGISTERED = 'event_registered';

    protected $fillable = [
        'recipient_client_id',
        'actor_client_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'recipient_client_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'actor_client_id');
    }
}
