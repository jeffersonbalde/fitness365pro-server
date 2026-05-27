<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class AdminEvent extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'admin_id',
        'title',
        'description',
        'how_it_works',
        'participant_rules',
        'image_url',
        'badges',
        'running_details',
        'gym_details',
        'delivery_areas',
        'location',
        'category',
        'location_type',
        'venue',
        'registration_deadline',
        'registration_starts_at',
        'starts_at',
        'ends_at',
        'fee',
        'fee_type',
        'mileage_challenge_km',
        'status',
        'publish_at',
        'expires_at',
    ];

    protected $casts = [
        'how_it_works' => 'array',
        'participant_rules' => 'array',
        'badges' => 'array',
        'running_details' => 'array',
        'gym_details' => 'array',
        'delivery_areas' => 'array',
        'registration_deadline' => 'datetime',
        'registration_starts_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'publish_at' => 'datetime',
        'expires_at' => 'datetime',
        'fee' => 'decimal:2',
        'mileage_challenge_km' => 'decimal:4',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ClientAdminEventRegistration::class, 'admin_event_id');
    }

    /**
     * Published CMS items that are visible within publish/expires windows.
     */
    public function scopePublishedVisible(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?? now('UTC');

        return $query
            ->where('status', 'published')
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            });
    }

    /** Events still open for participation (not yet ended). */
    public function scopeActive(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?? now('UTC');

        return $query
            ->publishedVisible($now)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    /** Events whose end date has passed — shown on race results only. */
    public function scopeCompleted(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?? now('UTC');

        return $query
            ->where('status', 'published')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            });
    }

    /**
     * Published events visible to confirmed registrants (active or completed).
     * Used for challenge history, not for new registrations.
     */
    public function scopePublishedForRegistrants(Builder $query, ?Carbon $now = null): Builder
    {
        $now = $now ?? now('UTC');

        return $query
            ->where('status', 'published')
            ->where(function (Builder $query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            });
    }
}

