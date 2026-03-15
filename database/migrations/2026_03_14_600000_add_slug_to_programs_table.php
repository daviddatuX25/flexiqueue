<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: program_slug unique per site, non-null, for /site/{site_slug}/{program_slug} URLs.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->after('name');
        });

        $programs = DB::table('programs')->get(['id', 'name', 'site_id']);
        $used = [];
        foreach ($programs as $p) {
            $base = Str::slug($p->name) ?: 'program';
            $slug = $base;
            $key = ($p->site_id ?? 0) . ':' . $slug;
            $n = 0;
            while (isset($used[$key])) {
                $n++;
                $slug = $base . '-' . $n;
                $key = ($p->site_id ?? 0) . ':' . $slug;
            }
            $used[$key] = true;
            DB::table('programs')->where('id', $p->id)->update(['slug' => $slug]);
        }

        Schema::table('programs', function (Blueprint $table) {
            $table->string('slug', 100)->nullable(false)->change();
            $table->unique(['site_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropUnique(['site_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
