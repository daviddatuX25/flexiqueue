<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device authorization requests: public device shows QR, supervisor scans to approve; device polls for cookie.
     */
    public function up(): void
    {
        Schema::create('device_authorization_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('device_key', 255)->comment('Device identifier sent by client for authorize()');
            $table->string('device_key_hash', 64)->index();
            $table->string('request_token', 64)->unique();
            $table->string('status', 16)->default('pending'); // pending, approved, rejected, cancelled
            $table->string('scope', 20)->default('session'); // session, persistent
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->text('approved_cookie_value')->nullable()->comment('Set when approved so poll response can Set-Cookie');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_authorization_requests');
    }
};
