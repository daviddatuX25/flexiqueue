<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per SUPER-ADMIN-VS-ADMIN-SPEC: admin-level action log (user/site/settings changes).
     * Super_admin sees only this scope; site admin sees full operational audit.
     */
    public function up(): void
    {
        Schema::create('admin_action_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action_log');
    }
};
