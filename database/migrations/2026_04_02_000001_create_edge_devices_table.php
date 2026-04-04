<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('device_token_hash', 255)->unique();
            $table->unsignedBigInteger('id_offset')->default(0);
            $table->enum('sync_mode', ['auto', 'end_of_event'])->default('auto');
            $table->boolean('supervisor_admin_access')->default(false);
            $table->foreignId('assigned_program_id')->nullable()->constrained('programs')->nullOnDelete();
            $table->boolean('session_active')->default(false);
            $table->string('app_version', 50)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('paired_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('force_cancelled_at')->nullable();
            $table->enum('update_status', ['up_to_date', 'update_available', 'updating', 'update_failed'])->default('up_to_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_devices');
    }
};
