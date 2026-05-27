<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventProgressSubmission extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_WORKOUT = 'workout';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'event_progress_submissions';

    protected $fillable = [
        'client_id',
        'admin_event_id',
        'workout_log_id',
        'source',
        'distance_delta_km',
        'pace_min_per_km',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'distance_delta_km' => 'decimal:4',
        'pace_min_per_km' => 'decimal:4',
        'reviewed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AdminEvent::class, 'admin_event_id');
    }

    public function workoutLog(): BelongsTo
    {
        return $this->belongsTo(WorkoutLog::class, 'workout_log_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }
}
