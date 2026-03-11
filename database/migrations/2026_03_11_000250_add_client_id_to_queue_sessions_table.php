<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per FLEXIQUEUE-XM2O configurable token-to-client binding:
     * add an optional association from queue_sessions to clients to record
     * which client (if any) a session is bound to.
     */
    public function up(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->foreignId('client_id')
                ->nullable()
                ->after('token_id')
                ->constrained('clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};

