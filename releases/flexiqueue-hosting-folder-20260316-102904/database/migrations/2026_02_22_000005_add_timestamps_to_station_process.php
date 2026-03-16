<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add timestamps to station_process for Laravel sync() compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_process', function (Blueprint $table) {
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('station_process', function (Blueprint $table) {
            $table->dropTimestamps();
        });
    }
};
