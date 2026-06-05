<?php

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Services\EventLeaderboardRankingService;
use App\Support\ViewerChallengeProgressPresenter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        Schema::table('client_admin_event_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at')) {
                $table->timestamp('progress_goal_completed_at')->nullable()->after('progress_submission_status');
            }
        });

        if (! Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at')) {
            return;
        }

        $ranking = app(EventLeaderboardRankingService::class);

        ClientAdminEventRegistration::query()
            ->whereNull('progress_goal_completed_at')
            ->whereNotNull('progress_logged_km')
            ->chunkById(100, function ($regs) use ($ranking) {
                foreach ($regs as $reg) {
                    $event = AdminEvent::query()->find($reg->admin_event_id);
                    if (! $event) {
                        continue;
                    }

                    $slice = ViewerChallengeProgressPresenter::sliceReadOnly($event, $reg, (string) $reg->client_id);
                    if (! ViewerChallengeProgressPresenter::distanceGoalIsSatisfied($slice)) {
                        continue;
                    }

                    $completedAt = $ranking->inferCompletionTimestamp($event, $reg);
                    $reg->progress_goal_completed_at = $completedAt ?? $reg->updated_at ?? now();
                    $reg->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        Schema::table('client_admin_event_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at')) {
                $table->dropColumn('progress_goal_completed_at');
            }
        });
    }
};
