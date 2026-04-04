<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('edge_locked_by_device_id')
                ->nullable()
                ->after('id')
                ->constrained('edge_devices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropForeign(['edge_locked_by_device_id']);
            $table->dropColumn('edge_locked_by_device_id');
        });
    }
};
