<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per PROCESS-STATION-REFACTOR: Logical work types. Stations M:M with processes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('name', 50);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'name'], 'idx_process_name_program');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
