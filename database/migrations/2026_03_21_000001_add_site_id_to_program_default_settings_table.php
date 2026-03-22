<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per docs/plans/PER_SITE_PROGRAM_DEFAULTS_AND_PLATFORM_TEMPLATE.md:
 * platform row (site_id null) + one row per site (same pattern as print_settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('program_default_settings')) {
            return;
        }

        Schema::table('program_default_settings', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                ->nullOnDelete();
        });

        $this->backfillProgramDefaultSettings();

        Schema::table('program_default_settings', function (Blueprint $table) {
            $table->unique('site_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('program_default_settings')) {
            return;
        }

        Schema::table('program_default_settings', function (Blueprint $table) {
            $table->dropUnique(['site_id']);
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }

    private function backfillProgramDefaultSettings(): void
    {
        $rows = DB::table('program_default_settings')->orderBy('id')->get();
        $settingsJson = '{}';

        if ($rows->isEmpty()) {
            DB::table('program_default_settings')->insert([
                'site_id' => null,
                'settings' => $settingsJson,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $keep = $rows->first();
            $settingsJson = $keep->settings ?? '{}';
            if ($rows->count() > 1) {
                DB::table('program_default_settings')->where('id', '!=', $keep->id)->delete();
            }
            DB::table('program_default_settings')->where('id', $keep->id)->update([
                'site_id' => null,
                'updated_at' => now(),
            ]);
        }

        $siteIds = DB::table('sites')->orderBy('id')->pluck('id');
        foreach ($siteIds as $siteId) {
            $exists = DB::table('program_default_settings')->where('site_id', $siteId)->exists();
            if (! $exists) {
                DB::table('program_default_settings')->insert([
                    'site_id' => $siteId,
                    'settings' => $settingsJson,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
