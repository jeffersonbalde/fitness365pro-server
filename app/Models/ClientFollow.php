<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientFollow extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'follower_client_id',
        'followed_client_id',
    ];

    public function follower(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'follower_client_id');
    }

    public function followed(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'followed_client_id');
    }
}

