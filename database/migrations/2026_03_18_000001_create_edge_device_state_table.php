<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('edge_device_state')) {
            return;
        }

        Schema::create('edge_device_state', function (Blueprint $table) {
            $table->id();
            $table->timestamp('paired_at')->nullable();
            $table->string('central_url', 500)->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('site_name', 255)->nullable();
            $table->text('device_token')->nullable();
            $table->enum('sync_mode', ['bridge', 'sync'])->default('sync');
            $table->boolean('supervisor_admin_access')->default(false);
            $table->unsignedBigInteger('active_program_id')->nullable();
            $table->string('active_program_name', 255)->nullable();
            $table->boolean('session_active')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_device_state');
    }
};
