<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkoutLog extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'client_id',
        'admin_event_id',
        'entry_type',
        'workout_date',
        'workout_type',
        'duration_minutes',
        'distance_km',
        'duration_seconds',
        'pace_min_per_km',
        'status',
        'caption',
        'location',
        'notes',
        'workout_images',
        'plan_day',
        'challenge_progress_approved_km',
    ];

    protected $casts = [
        'entry_type' => 'string',
        'workout_date' => 'date',
        'duration_minutes' => 'integer',
        'distance_km' => 'decimal:2',
        'duration_seconds' => 'integer',
        'pace_min_per_km' => 'decimal:2',
        'challenge_progress_approved_km' => 'decimal:4',
        'caption' => 'string',
        'location' => 'string',
        'workout_images' => 'array',
        'plan_day' => 'integer',
    ];

    public function linkedAdminEvent(): BelongsTo
    {
        return $this->belongsTo(AdminEvent::class, 'admin_event_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(WorkoutLike::class, 'workout_log_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkoutComment::class, 'workout_log_id');
    }
}
