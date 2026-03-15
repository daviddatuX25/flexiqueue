<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per addition-to-public-site-plan Part 2.1: is_published controls visibility on site landing.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->after('is_paused');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('is_published');
        });
    }
};
