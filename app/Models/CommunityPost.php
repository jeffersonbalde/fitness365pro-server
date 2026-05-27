<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunityPost extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'community_id',
        'client_id',
        'body',
        'media_urls',
        'status',
    ];

    protected $casts = [
        'media_urls' => 'array',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}

