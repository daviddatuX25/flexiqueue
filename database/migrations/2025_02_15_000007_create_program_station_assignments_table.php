<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per refactor plan: per-program station assignment (staff can have different station per program).
     */
    public function up(): void
    {
        Schema::create('program_station_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['program_id', 'user_id'], 'idx_program_station_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_station_assignments');
    }
};
