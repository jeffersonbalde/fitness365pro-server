<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->enum('delivery_status', ['sent', 'delivered', 'read'])->default('sent')->after('attachments');
            $table->timestamp('delivered_at')->nullable()->after('delivery_status');
            $table->timestamp('read_at')->nullable()->after('delivered_at');

            $table->index(['conversation_id', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'delivery_status']);
            $table->dropColumn(['delivery_status', 'delivered_at', 'read_at']);
        });
    }
};

