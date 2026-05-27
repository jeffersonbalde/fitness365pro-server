<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatBlock extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'blocker_client_id',
        'blocked_client_id',
        'reason',
    ];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'blocker_client_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'blocked_client_id');
    }
}

