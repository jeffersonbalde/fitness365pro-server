<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_events') || !Schema::hasTable('clients')) {
            return;
        }

        Schema::create('client_admin_event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('admin_event_id');
            $table->timestamps();

            $table->unique(['client_id', 'admin_event_id'], 'cae_reg_cli_evt_uidx');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('admin_event_id')->references('id')->on('admin_events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_admin_event_registrations');
    }
};
