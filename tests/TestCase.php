<?php

namespace Tests;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\User;
use App\Services\ProgramSupervisorGrantService;
use App\Services\SpatieRbacSyncService;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Device refactor: local .env may disable FEATURE_STAFF_TRIAGE_PAGE; tests must opt out explicitly.
        config(['flexiqueue.staff_triage_page_enabled' => true]);
        // Spatie RBAC: seed after migrations (RefreshDatabase). Unit tests without DB skip.
        if (Schema::hasTable('permissions')) {
            $this->seed(PermissionCatalogSeeder::class);
        }
        if (config('permission.teams')) {
            setPermissionsTeamId(RbacTeam::GLOBAL_TEAM_ID);
        }
    }

    /**
     * Ensure the app never uses the developer's MySQL .env when cached config was cleared
     * or env vars leak from the shell.
     */
    public function createApplication(): Application
    {
        $this->forceIsolatedTestDatabaseEnv();

        return parent::createApplication();
    }

    protected function forceIsolatedTestDatabaseEnv(): void
    {
        foreach ([
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** Grant `programs.supervise` on the program RbacTeam and sync dashboard/supervisor-tool direct perms (replaces pivot + observer). */
    protected function grantProgramTeamSuperviseForTests(User $user, Program $program): void
    {
        app(ProgramSupervisorGrantService::class)->grantProgramTeamSupervise($user, $program);
        app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($user->fresh());
    }

    protected function revokeProgramTeamSuperviseForTests(User $user, Program $program): void
    {
        app(ProgramSupervisorGrantService::class)->revokeProgramTeamSupervise($user, $program);
        app(SpatieRbacSyncService::class)->syncSupervisorDirectPermissions($user->fresh());
    }
}
