<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: identity registration requests from public triage when ID not found.
     * Staff verify/complete name, birth_year, client_category and accept or reject.
     */
    public function up(): void
    {
        Schema::create('identity_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('queue_sessions')->nullOnDelete();
            $table->string('name', 150)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('client_category', 50)->nullable();
            $table->string('status', 20)->default('pending'); // pending, accepted, rejected
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->index(['program_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_registrations');
    }
};
