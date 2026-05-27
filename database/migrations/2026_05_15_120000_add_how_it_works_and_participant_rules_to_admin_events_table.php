<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function defaultHowItWorksJson(): string
    {
        return json_encode([
            'Register before the deadline to secure your slot.',
            'Complete the required distance within the event period.',
            'Upload or log your workout progress in-app to validate participation.',
            'Claim rewards and badges after event verification.',
        ]);
    }

    private function defaultParticipantRulesJson(): string
    {
        return json_encode([
            'One account per participant only.',
            'Entries must be submitted before the registration deadline.',
            'Any misleading or duplicate submissions may be disqualified.',
        ]);
    }

    public function up(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }

        Schema::table('admin_events', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_events', 'how_it_works')) {
                $table->json('how_it_works')->nullable()->after('description');
            }
            if (!Schema::hasColumn('admin_events', 'participant_rules')) {
                $table->json('participant_rules')->nullable()->after('how_it_works');
            }
        });

        $how = $this->defaultHowItWorksJson();
        $rules = $this->defaultParticipantRulesJson();

        DB::table('admin_events')
            ->whereNull('how_it_works')
            ->update(['how_it_works' => $how, 'participant_rules' => $rules]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }

        Schema::table('admin_events', function (Blueprint $table) {
            if (Schema::hasColumn('admin_events', 'participant_rules')) {
                $table->dropColumn('participant_rules');
            }
            if (Schema::hasColumn('admin_events', 'how_it_works')) {
                $table->dropColumn('how_it_works');
            }
        });
    }
};
