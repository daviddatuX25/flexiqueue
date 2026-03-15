<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per 04-DATA-MODEL.md Table 2.
     */
    public function up(): void
    {
        Schema::create('service_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('name', 50);
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('color_code', 7)->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'name'], 'idx_track_name_program');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tracks');
    }
};
