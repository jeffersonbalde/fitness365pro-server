<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientProfile extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'client_profiles';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'client_id',
        'first_name',
        'last_name',
        'display_name',
        'bio',
        'date_of_birth',
        'gender',
        'height_cm',
        'current_weight_kg',
        'target_weight_kg',
        'bmi',
        'bmi_category',
        'body_type',
        'profile_picture_url',
        'cover_photo_url',
        'city',
        'province',
        'country',
        'street_address',
        'barangay',
        'phone',
        'timezone',
        'activity_level',
        'experience_level',
        'experience_running',
        'experience_gym',
        'experience_others_title',
        'experience_others',
        'primary_niche',
        'secondary_niches',
        'workout_preferences',
        'nutrition_preferences',
        'theme_mode',
        'onboarding_step',
        'onboarding_completed',
        'fitness_plan',
        'ai_greeting_message',
        'ai_recommendations',
        'target_days',
        'target_weight_change_kg',
        'plan_start_date',
        'plan_end_date',
        'fitness_plan_generated_at',
        'fitness_plan_status',
        'fitness_plan_error',
        'fitness_plan_source',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'height_cm' => 'integer',
        'current_weight_kg' => 'decimal:2',
        'target_weight_kg' => 'decimal:2',
        'bmi' => 'decimal:2',
        'workout_preferences' => 'array',
        'nutrition_preferences' => 'array',
        'secondary_niches' => 'array',
        'fitness_plan' => 'array',
        'ai_recommendations' => 'array',
        'onboarding_step' => 'integer',
        'onboarding_completed' => 'boolean',
        'target_days' => 'integer',
        'target_weight_change_kg' => 'decimal:2',
        'plan_start_date' => 'date',
        'plan_end_date' => 'date',
        'fitness_plan_generated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function getFullNameAttribute(): string
    {
        if ($this->first_name || $this->last_name) {
            return trim("{$this->first_name} {$this->last_name}");
        }
        return $this->display_name ?? 'User';
    }
}
