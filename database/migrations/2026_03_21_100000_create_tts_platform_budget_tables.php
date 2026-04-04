<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_platform_budgets', function (Blueprint $table) {
            $table->id();
            $table->boolean('global_enabled')->default(false);
            $table->string('period', 16)->default('monthly'); // daily|monthly
            $table->string('mode', 16)->default('chars'); // chars|minutes
            $table->unsignedInteger('char_limit')->default(0);
            $table->boolean('block_on_limit')->default(true);
            $table->unsignedTinyInteger('warning_threshold_pct')->default(80);
            $table->timestamps();
        });

        Schema::create('tts_site_budget_weights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('weight')->default(1);
            $table->timestamps();

            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_site_budget_weights');
        Schema::dropIfExists('tts_platform_budgets');
    }
};
