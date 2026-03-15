<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per default-site plan: one site per deployment can be marked default (for public display/triage).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('edge_settings');
        });

        $firstId = DB::table('sites')->orderBy('id')->value('id');
        if ($firstId !== null) {
            DB::table('sites')->where('id', $firstId)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
