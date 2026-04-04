<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->unique();
            $table->string('status', 20)->default('pending'); // pending, complete, partial, failed
            $table->json('payload_summary')->nullable();      // { queue_sessions: 10, transaction_logs: 50, ... }
            $table->json('receipt_data')->nullable();          // central's response
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_sync_receipts');
    }
};
