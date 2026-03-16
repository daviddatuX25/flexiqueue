<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per TTS plan: global server-side TTS voice and rate for tokens.
     */
    public function up(): void
    {
        Schema::create('token_tts_settings', function (Blueprint $table) {
            $table->id();
            $table->string('voice_id', 200)->nullable();
            $table->float('rate')->default((float) config('tts.default_rate', 0.84));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_tts_settings');
    }
};

