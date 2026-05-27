<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutLike extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'workout_log_id',
        'client_id',
    ];

    public function workout(): BelongsTo
    {
        return $this->belongsTo(WorkoutLog::class, 'workout_log_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
