<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per 04-DATA-MODEL.md Table 3.
     */
    public function up(): void
    {
        Schema::create('track_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained('service_tracks')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->unsignedInteger('step_order');
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->timestamps();

            $table->unique(['track_id', 'step_order'], 'idx_step_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_steps');
    }
};
