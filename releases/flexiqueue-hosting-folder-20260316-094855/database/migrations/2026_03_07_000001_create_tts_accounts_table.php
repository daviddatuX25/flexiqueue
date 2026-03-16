<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per ELEVENLABS-DYNAMIC-ACCOUNTS-AND-VOICES: store multiple ElevenLabs accounts; one active at a time.
     */
    public function up(): void
    {
        Schema::create('tts_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label', 255);
            $table->text('api_key'); // encrypted via Crypt
            $table->string('model_id', 100)->default('eleven_multilingual_v2');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::table('tts_accounts', function (Blueprint $table) {
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_accounts');
    }
};
