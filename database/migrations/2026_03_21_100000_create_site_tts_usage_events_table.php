<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only usage events for TTS generation metering.
     * Per tts_robustness roadmap: record site_id, provider, chars, source (job|preview).
     */
    public function up(): void
    {
        Schema::create('site_tts_usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('provider', 32);
            $table->unsignedInteger('chars_used');
            $table->string('source', 32); // 'job' | 'preview'
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_tts_usage_events');
    }
};
