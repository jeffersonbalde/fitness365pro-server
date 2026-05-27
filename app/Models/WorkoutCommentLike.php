<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutCommentLike extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'workout_comment_id',
        'client_id',
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(WorkoutComment::class, 'workout_comment_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
