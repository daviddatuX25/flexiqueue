<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §2 Migration 2.
 * Add identity_hash to clients (no index/unique). Not exposed in API or UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('identity_hash', 64)->nullable()->after('mobile_hash');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('identity_hash');
        });
    }
};
