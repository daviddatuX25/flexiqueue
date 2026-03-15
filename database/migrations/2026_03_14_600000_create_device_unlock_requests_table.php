<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device unlock requests: public device shows QR, supervisor scans to approve; then device clears lock and goes to choose page.
     */
    public function up(): void
    {
        Schema::create('device_unlock_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('request_token', 64)->unique();
            $table->string('status', 16)->default('pending'); // pending, approved, rejected, cancelled
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_unlock_requests');
    }
};
