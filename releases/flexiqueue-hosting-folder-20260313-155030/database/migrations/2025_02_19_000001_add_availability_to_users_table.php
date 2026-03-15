<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per staff-availability-status plan: add user availability for footer + public display.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('availability_status', 20)->default('offline')->after('is_active');
            $table->timestamp('availability_updated_at')->nullable()->after('availability_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['availability_status', 'availability_updated_at']);
        });
    }
};
