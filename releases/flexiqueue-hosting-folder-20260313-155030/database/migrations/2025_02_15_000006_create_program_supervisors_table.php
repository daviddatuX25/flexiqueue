<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per refactor plan: program-specific supervisor permission (staff can be supervisor for specific programs).
     */
    public function up(): void
    {
        Schema::create('program_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['program_id', 'user_id'], 'idx_program_supervisor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_supervisors');
    }
};
