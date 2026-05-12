<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('revoked_at');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->unsignedBigInteger('approved_by')->nullable()->after('rejected_at');

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();

            // paired_at becomes nullable because pending-approval devices don't have a paired_at yet
            $table->timestamp('paired_at')->nullable()->change();
            // device_token_hash becomes nullable for pending-approval devices
            $table->string('device_token_hash', 255)->nullable()->change();
        });

        Schema::table('edge_device_state', function (Blueprint $table) {
            $table->string('approval_status', 20)->nullable()->after('is_revoked');
            $table->unsignedBigInteger('central_device_id')->nullable()->after('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('edge_devices', function (Blueprint $table) {
            // Restore NOT NULL constraints
            $table->timestamp('paired_at')->nullable(false)->change();
            $table->string('device_token_hash', 255)->nullable(false)->change();

            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approval_status', 'approved_at', 'rejected_at', 'approved_by']);
        });

        Schema::table('edge_device_state', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'central_device_id']);
        });
    }
};