<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAdminEventRegistration extends Model
{
    use HasUuids;

    protected $table = 'client_admin_event_registrations';

    protected $fillable = [
        'client_id',
        'admin_event_id',
        'participant_details',
        'delivery_details',
        'delivery_fee_snapshot',
        'registration_status',
        'payment_status',
        'amount_snapshot',
        'paymaya_checkout_id',
        'paymaya_rrn',
        'paymaya_payment_status_snapshot',
        'progress_logged_km',
        'progress_goal_km',
        'progress_target_label',
        'progress_pace_min_per_km',
        'progress_submission_status',
        'progress_goal_completed_at',
        'payment_method',
        'registered_by_admin_id',
        'manual_payment_reference',
        'admin_registration_note',
        'paid_at',
    ];

    protected $casts = [
        'amount_snapshot' => 'decimal:2',
        'delivery_fee_snapshot' => 'decimal:2',
        'progress_logged_km' => 'decimal:4',
        'progress_goal_km' => 'decimal:4',
        'progress_pace_min_per_km' => 'decimal:4',
        'participant_details' => 'array',
        'delivery_details' => 'array',
        'paid_at' => 'datetime',
        'progress_goal_completed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AdminEvent::class, 'admin_event_id');
    }

    public function registeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'registered_by_admin_id');
    }
}
