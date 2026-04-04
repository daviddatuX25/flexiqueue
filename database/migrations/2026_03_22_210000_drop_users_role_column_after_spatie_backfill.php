<?php

use App\Models\RbacTeam;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for primary role: Spatie global-team roles (see PermissionCatalogSeeder).
 * Backfills from legacy users.role, then drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            foreach (DB::table('users')->select('id', 'role')->orderBy('id')->cursor() as $row) {
                $name = (string) ($row->role ?? '');
                if ($name === '') {
                    continue;
                }
                $user = User::query()->find($row->id);
                if ($user === null) {
                    continue;
                }
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $user->syncRoles([$name]);
            }
        } finally {
            setPermissionsTeamId($previous);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('staff')->after('password');
        });

        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        try {
            foreach (User::query()->orderBy('id')->cursor() as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $name = $user->roles()->first()?->name ?? 'staff';
                DB::table('users')->where('id', $user->id)->update(['role' => $name]);
            }
        } finally {
            setPermissionsTeamId($previous);
        }
    }
};
