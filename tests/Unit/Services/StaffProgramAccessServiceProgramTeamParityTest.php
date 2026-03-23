<?php

namespace Tests\Unit\Services;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\Site;
use App\Models\User;
use App\Services\StaffProgramAccessService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffProgramAccessServiceProgramTeamParityTest extends TestCase
{
    use RefreshDatabase;

    private function grantProgramsSuperviseOnProgramTeam(User $user, Program $program): void
    {
        RbacTeam::forProgram($program);
        $previous = getPermissionsTeamId();
        setPermissionsTeamId(RbacTeam::forProgram($program)->id);
        try {
            $user->givePermissionTo(PermissionCatalog::PROGRAMS_SUPERVISE);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    public function test_may_use_program_picker_when_only_program_team_supervise_same_site_scope(): void
    {
        $staff = User::factory()->create(['assigned_station_id' => null, 'site_id' => null]);
        $program = Program::create([
            'name' => 'P1',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $this->grantProgramsSuperviseOnProgramTeam($staff, $program);

        $service = app(StaffProgramAccessService::class);
        $this->assertTrue($service->mayUseProgramPickerWithoutAssignedStation($staff));
    }

    public function test_may_bypass_supervisor_auth_only_for_matching_program_when_team_scoped(): void
    {
        $staff = User::factory()->create(['site_id' => null]);
        $p1 = Program::create([
            'name' => 'P1',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $p2 = Program::create([
            'name' => 'P2',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => null,
        ]);
        $this->grantProgramsSuperviseOnProgramTeam($staff, $p1);

        $service = app(StaffProgramAccessService::class);
        $this->assertTrue($service->mayBypassSupervisorInteractiveAuth($staff, $p1));
        $this->assertFalse($service->mayBypassSupervisorInteractiveAuth($staff, $p2));
        $this->assertFalse($service->mayBypassSupervisorInteractiveAuth($staff, null));
    }

    public function test_may_use_program_picker_false_when_team_supervise_only_on_other_site_program(): void
    {
        $siteA = Site::query()->create([
            'name' => 'Site A',
            'slug' => 'a-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $siteB = Site::query()->create([
            'name' => 'Site B',
            'slug' => 'b-'.Str::random(8),
            'api_key_hash' => 'hash-'.Str::random(32),
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);
        $staff = User::factory()->create(['assigned_station_id' => null, 'site_id' => $siteA->id]);
        $programOnB = Program::create([
            'name' => 'PB',
            'description' => null,
            'is_active' => true,
            'created_by' => $staff->id,
            'site_id' => $siteB->id,
        ]);
        $this->grantProgramsSuperviseOnProgramTeam($staff, $programOnB);

        $service = app(StaffProgramAccessService::class);
        $this->assertFalse($service->mayUseProgramPickerWithoutAssignedStation($staff));
    }
}
