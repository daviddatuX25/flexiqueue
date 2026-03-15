<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per station holding-area plan: add per-session holding metadata and per-station holding capacity.
     */
    public function up(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->foreignId('holding_station_id')
                ->nullable()
                ->after('current_station_id')
                ->constrained('stations');
            $table->boolean('is_on_hold')
                ->nullable()
                ->after('holding_station_id');
            $table->timestamp('held_at')
                ->nullable()
                ->after('is_on_hold');
            $table->unsignedInteger('held_order')
                ->nullable()
                ->after('held_at');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->unsignedTinyInteger('holding_capacity')
                ->default(3)
                ->after('client_capacity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holding_station_id');
            $table->dropColumn(['is_on_hold', 'held_at', 'held_order']);
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('holding_capacity');
        });
    }
};

