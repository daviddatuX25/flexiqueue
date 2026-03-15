<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Display settings requests: public device shows QR, supervisor scans on Program Overrides to approve.
     * Separate table to avoid nullable session_id on permission_requests.
     */
    public function up(): void
    {
        Schema::create('display_settings_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('request_token', 64)->unique();
            $table->string('status', 16)->default('pending'); // pending, approved, rejected, cancelled
            $table->json('settings_payload'); // display_audio_muted, display_audio_volume, etc.
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('display_settings_requests');
    }
};
