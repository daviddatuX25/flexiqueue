<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For SQLite (used in tests), drop and recreate the enum column.
        // For MySQL/PostgreSQL, use a raw ALTER to change the enum.
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('program_audit_log', function (Blueprint $table) {
                $table->dropColumn('action');
            });
            Schema::table('program_audit_log', function (Blueprint $table) {
                $table->enum('action', [
                    'session_start',
                    'session_stop',
                    'edge_session_force_cancelled',
                ])->after('staff_user_id');
            });
        } else {
            DB::statement("ALTER TABLE program_audit_log MODIFY COLUMN action ENUM('session_start', 'session_stop', 'edge_session_force_cancelled')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('program_audit_log', function (Blueprint $table) {
                $table->dropColumn('action');
            });
            Schema::table('program_audit_log', function (Blueprint $table) {
                $table->enum('action', ['session_start', 'session_stop'])->after('staff_user_id');
            });
        } else {
            DB::statement("ALTER TABLE program_audit_log MODIFY COLUMN action ENUM('session_start', 'session_stop')");
        }
    }
};
