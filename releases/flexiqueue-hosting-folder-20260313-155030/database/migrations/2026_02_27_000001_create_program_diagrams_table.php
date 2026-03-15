<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per program diagram visualizer plan: one diagram (layout JSON) per program.
     */
    public function up(): void
    {
        Schema::create('program_diagrams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->json('layout')->nullable();
            $table->timestamps();

            $table->unique('program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_diagrams');
    }
};
