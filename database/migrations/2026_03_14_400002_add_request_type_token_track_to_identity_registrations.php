<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §2 Migration 3.
 * Add request_type (default 'registration'), token_id, track_id to identity_registrations for FLOW A/B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->string('request_type', 50)->default('registration')->after('program_id');
            $table->foreignId('token_id')->nullable()->after('session_id')->constrained('tokens')->nullOnDelete();
            $table->foreignId('track_id')->nullable()->after('token_id')->constrained('service_tracks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropForeign(['token_id']);
            $table->dropForeign(['track_id']);
            $table->dropColumn(['request_type', 'token_id', 'track_id']);
        });
    }
};
