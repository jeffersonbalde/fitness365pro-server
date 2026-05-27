<?php

namespace App\Http\Controllers\Api\Admin\Concerns;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

trait LogsAdminActivity
{
    protected function logAdminActivity(
        Request $request,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $meta = []
    ): void {
        $admin = $request->user();
        ActivityLog::create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meta' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}

