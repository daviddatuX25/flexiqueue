<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-phase4 governance: rollups for fast budget checks.
     * Keyed by site_id + period_key (YYYY-MM-DD daily, YYYY-MM monthly).
     */
    public function up(): void
    {
        Schema::create('site_tts_usage_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('period_key', 16); // YYYY-MM-DD or YYYY-MM
            $table->unsignedInteger('chars_used')->default(0);
            $table->unsignedInteger('generation_count')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_tts_usage_rollups');
    }
};
