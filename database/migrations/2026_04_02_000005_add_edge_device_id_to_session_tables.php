<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'queue_sessions',
        'transaction_logs',
        'clients',
        'identity_registrations',
        'program_audit_log',
        'staff_activity_log',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'edge_device_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('edge_device_id')->nullable()->after('id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'edge_device_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('edge_device_id');
                });
            }
        }
    }
};
