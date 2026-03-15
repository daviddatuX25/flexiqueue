<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add tokens.is_global: when true, token is usable for any program in the same site
 * without requiring a program_token row. Program token list shows assigned + global tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->boolean('is_global')->default(false)->after('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });
    }
};
