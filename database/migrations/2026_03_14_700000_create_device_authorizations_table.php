<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per plan Step 5: Program code + PIN/QR device authorization.
 * Stores device authorizations so a device (cookie) can use a program's display/triage
 * after supervisor PIN/QR verification. Session-scoped = valid only while program is active;
 * persistent = valid until program deleted or revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('device_key_hash', 255)->comment('Hash of device identifier for idempotent re-auth');
            $table->string('scope', 20)->comment('session|persistent');
            $table->string('cookie_token_hash', 255)->comment('Hash of token sent in cookie for validation');
            $table->timestamps();

            $table->unique(['program_id', 'device_key_hash']);
            $table->index('cookie_token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_authorizations');
    }
};
