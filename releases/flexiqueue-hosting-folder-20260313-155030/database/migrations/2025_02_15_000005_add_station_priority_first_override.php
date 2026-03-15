<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: station can override program's priority_first setting.
     */
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('priority_first_override')->nullable()->after('client_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('priority_first_override');
        });
    }
};
