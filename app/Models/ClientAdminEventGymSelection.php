<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAdminEventGymSelection extends Model
{
    use HasUuids;

    protected $table = 'client_admin_event_gym_selections';

    protected $fillable = [
        'client_id',
        'admin_event_id',
        'program_key',
        'program_label',
        'package_key',
        'package_label',
        'package_includes_shirt',
        'shirt_size',
    ];

    protected $casts = [
        'package_includes_shirt' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AdminEvent::class, 'admin_event_id');
    }
}
