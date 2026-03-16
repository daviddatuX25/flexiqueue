<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per refactor plan: staff can suggest programs; admin accepts to create real program.
     */
    public function up(): void
    {
        Schema::create('program_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->json('schema_json'); // tracks, stations, steps
            $table->string('status', 20)->default('pending'); // pending, accepted, rejected
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_suggestions');
    }
};
