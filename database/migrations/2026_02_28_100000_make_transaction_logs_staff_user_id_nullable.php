<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: public self-serve bind records null staff_user_id (audit shows "Public").
     */
    public function up(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->dropForeign(['staff_user_id']);
        });
        DB::statement('ALTER TABLE transaction_logs MODIFY staff_user_id BIGINT UNSIGNED NULL');
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->foreign('staff_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->dropForeign(['staff_user_id']);
        });
        DB::statement('ALTER TABLE transaction_logs MODIFY staff_user_id BIGINT UNSIGNED NOT NULL');
        Schema::table('transaction_logs', function (Blueprint $table) {
            $table->foreign('staff_user_id')->references('id')->on('users');
        });
    }
};
