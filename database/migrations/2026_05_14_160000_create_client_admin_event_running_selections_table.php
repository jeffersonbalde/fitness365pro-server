<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_admin_event_running_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->uuid('admin_event_id');
            $table->string('distance_key', 16);
            $table->string('distance_label', 120)->nullable();
            $table->string('package_key', 32);
            $table->string('package_label', 200)->nullable();
            $table->boolean('package_includes_shirt')->default(false);
            $table->string('shirt_size', 8)->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'admin_event_id'], 'cae_run_sel_client_event_uidx');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('admin_event_id')->references('id')->on('admin_events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_admin_event_running_selections');
    }
};
