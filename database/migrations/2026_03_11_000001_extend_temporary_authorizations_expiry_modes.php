<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temporary_authorizations', function (Blueprint $table) {
            $table->string('expiry_mode', 20)->default('time_or_usage')->after('type');
            $table->unsignedInteger('max_uses')->nullable()->default(1)->after('expiry_mode');
            $table->unsignedInteger('used_count')->default(0)->after('max_uses');
            $table->timestamp('last_used_at')->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('temporary_authorizations', function (Blueprint $table) {
            $table->dropColumn(['expiry_mode', 'max_uses', 'used_count', 'last_used_at']);
        });
    }
};

