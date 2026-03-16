<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permission requests: staff requests override/force-complete approval;
     * supervisor/admin approves or rejects. Real-time updates.
     */
    public function up(): void
    {
        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('queue_sessions')->cascadeOnDelete();
            $table->string('action_type', 32); // override, force_complete
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending, approved, rejected
            $table->foreignId('target_station_id')->nullable()->constrained('stations')->nullOnDelete(); // for override
            $table->text('reason');
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_requests');
    }
};
