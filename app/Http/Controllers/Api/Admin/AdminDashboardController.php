<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAnnouncement;
use App\Models\AdminPost;
use App\Models\Client;
use App\Models\ProgramCatalog;
use App\Models\WorkoutLog;
use Illuminate\Support\Facades\Schema;

class AdminDashboardController extends Controller
{
    public function stats()
    {
        if (!Schema::hasTable('admin_posts') || !Schema::hasTable('admin_announcements') || !Schema::hasTable('program_catalogs')) {
            return response()->json([
                'success' => true,
                'data' => [
                    'total_users' => Client::count(),
                    'active_sessions' => Client::where('updated_at', '>=', now()->subDays(7))->count(),
                    'open_reports' => WorkoutLog::whereDate('created_at', '>=', now()->subDays(7))->count(),
                    'notifications' => 0,
                    'published_posts' => 0,
                    'program_catalog_items' => 0,
                ],
            ]);
        }

        $now = now();
        $publishedPosts = AdminPost::query()
            ->where('status', 'published')
            ->where(function ($query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->count();

        $publishedAnnouncements = AdminAnnouncement::query()
            ->where('status', 'published')
            ->where(function ($query) use ($now) {
                $query->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_users' => Client::count(),
                'active_sessions' => Client::where('updated_at', '>=', now()->subDays(7))->count(),
                'open_reports' => WorkoutLog::whereDate('created_at', '>=', now()->subDays(7))->count(),
                'notifications' => $publishedAnnouncements,
                'published_posts' => $publishedPosts,
                'program_catalog_items' => ProgramCatalog::where('is_active', true)->count(),
            ],
        ]);
    }
}

