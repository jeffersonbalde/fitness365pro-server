<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'reporter_client_id',
        'message_id',
        'reason',
        'notes',
        'status',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'reporter_client_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}

