<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkoutComment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'workout_log_id',
        'client_id',
        'parent_comment_id',
        'body',
    ];

    public function workout(): BelongsTo
    {
        return $this->belongsTo(WorkoutLog::class, 'workout_log_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->orderBy('created_at');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(WorkoutCommentLike::class, 'workout_comment_id');
    }
}
