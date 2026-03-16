<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per 04-DATA-MODEL.md Table 8 — extend Laravel users with FlexiQueue fields.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'supervisor', 'staff'])->default('staff')->after('password');
            $table->string('override_pin', 255)->nullable()->after('role');
            $table->foreignId('assigned_station_id')->nullable()->after('override_pin')->constrained('stations')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('assigned_station_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['assigned_station_id']);
            $table->dropColumn(['role', 'override_pin', 'assigned_station_id', 'is_active']);
        });
    }
};
