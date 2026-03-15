<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: link session to identity registration when created via "request identification registration".
     * Unverified = session has identity_registration_id and registration.status === 'pending'.
     */
    public function up(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->foreignId('identity_registration_id')
                ->nullable()
                ->after('client_id')
                ->constrained('identity_registrations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_registration_id');
        });
    }
};
