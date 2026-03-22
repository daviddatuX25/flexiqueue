<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Surrogate team IDs for Spatie teams (site/program/global).
     *
     * @see docs/architecture/PERMISSIONS-TEAMS-AND-UI.md
     */
    public function up(): void
    {
        Schema::create('rbac_teams', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique('site_id');
            $table->unique('program_id');
        });

        $now = now();
        $globalId = 1;

        DB::table('rbac_teams')->insert([
            'id' => $globalId,
            'type' => 'global',
            'site_id' => null,
            'program_id' => null,
            'name' => 'Global',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            DB::statement('ALTER TABLE rbac_teams AUTO_INCREMENT = '.($globalId + 1));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_teams');
    }
};
