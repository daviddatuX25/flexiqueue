<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_sync_queue', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('attempts');
            $table->unsignedBigInteger('transaction_log_id')->nullable()->after('id');
            $table->unsignedBigInteger('session_id')->nullable()->after('transaction_log_id');
            $table->timestamp('synced_at')->nullable()->after('last_attempted_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('edge_sync_queue', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'transaction_log_id', 'session_id', 'synced_at']);
        });
    }
};
