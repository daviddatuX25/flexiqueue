<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per site-scoping-migration-spec §4 — Print settings (S.5).
 * Add print_settings.site_id (nullable FK); unique one row per site; backfill existing to default site.
 * SQLite + MariaDB compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->foreignId('site_id')
                ->nullable()
                ->after('id')
                ->constrained('sites')
                ->nullOnDelete();
        });

        Schema::table('print_settings', function (Blueprint $table) {
            $table->unique('site_id');
        });

        $this->backfillPrintSettingsSiteId();
    }

    public function down(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->dropUnique(['site_id']);
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }

    /**
     * Backfill: assign existing print_settings row(s) to default site.
     * One row per site: keep first row (by id) for default site; delete any other legacy rows.
     */
    private function backfillPrintSettingsSiteId(): void
    {
        $defaultSite = Site::orderBy('id')->first();
        if (! $defaultSite) {
            return;
        }

        $ids = DB::table('print_settings')->whereNull('site_id')->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        $firstId = $ids->first();
        DB::table('print_settings')->where('id', $firstId)->update(['site_id' => $defaultSite->id]);

        $rest = $ids->skip(1)->all();
        if ($rest !== []) {
            DB::table('print_settings')->whereIn('id', $rest)->delete();
        }
    }
};
