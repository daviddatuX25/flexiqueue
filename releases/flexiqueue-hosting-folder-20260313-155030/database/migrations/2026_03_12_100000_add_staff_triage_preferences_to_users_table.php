<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per plan: staff triage HID/camera preferences ("on this account").
     * Default true so existing users keep both enabled.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('staff_triage_allow_hid_barcode')->default(true)->after('avatar_path');
            $table->boolean('staff_triage_allow_camera_scanner')->default(true)->after('staff_triage_allow_hid_barcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['staff_triage_allow_hid_barcode', 'staff_triage_allow_camera_scanner']);
        });
    }
};
