<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Add request_token for QR payload (flexiqueue:permission_request:{id}:{request_token}).
     * Required to prevent guessable ID attacks. Backfill existing rows then make non-null.
     */
    public function up(): void
    {
        Schema::table('permission_requests', function (Blueprint $table) {
            $table->string('request_token', 64)->nullable()->after('status');
        });

        foreach (DB::table('permission_requests')->select('id')->cursor() as $row) {
            DB::table('permission_requests')->where('id', $row->id)->update([
                'request_token' => Str::random(64),
            ]);
        }

        Schema::table('permission_requests', function (Blueprint $table) {
            $table->unique('request_token');
        });
    }

    public function down(): void
    {
        Schema::table('permission_requests', function (Blueprint $table) {
            $table->dropUnique(['request_token']);
        });
        Schema::table('permission_requests', function (Blueprint $table) {
            $table->dropColumn('request_token');
        });
    }
};
