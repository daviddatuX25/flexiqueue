<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per central-edge-v2-final §Phase B — Multi-Tenant / Sites.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }
};

