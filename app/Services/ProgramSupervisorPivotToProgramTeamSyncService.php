<?php

namespace App\Services;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * R3: For each `program_supervisors` row, ensure `programs.supervise` is granted on that program's
 * {@see RbacTeam} (Spatie `team_id`). Idempotent — safe to run after deploy or before cutover.
 *
 * Does not remove the pivot table; {@see RBAC_AND_IDENTITY_END_STATE.md} R4 drops pivot after verification.
 */
final class ProgramSupervisorPivotToProgramTeamSyncService
{
    /**
     * @return array{
     *     pivot_rows: int,
     *     granted: int,
     *     already_had: int,
     *     skipped_missing: int,
     *     dry_run: bool,
     *     errors: list<string>
     * }
     */
    public function sync(bool $dryRun): array
    {
        if (! Schema::hasTable('program_supervisors')) {
            return [
                'pivot_rows' => 0,
                'granted' => 0,
                'already_had' => 0,
                'skipped_missing' => 0,
                'dry_run' => $dryRun,
                'errors' => [],
                'table_missing' => true,
            ];
        }

        $pivotRows = (int) DB::table('program_supervisors')->count();
        $granted = 0;
        $alreadyHad = 0;
        $skippedMissing = 0;
        $errors = [];

        $cursor = DB::table('program_supervisors')
            ->select('program_id', 'user_id')
            ->orderBy('program_id')
            ->orderBy('user_id')
            ->cursor();

        foreach ($cursor as $row) {
            try {
                $program = Program::query()->find($row->program_id);
                $user = User::query()->find($row->user_id);
                if ($program === null || $user === null) {
                    $skippedMissing++;

                    continue;
                }

                $team = RbacTeam::forProgram($program);

                if ($this->userAlreadyHasProgramTeamSuperviseDirect($user, $team)) {
                    $alreadyHad++;

                    continue;
                }

                if ($dryRun) {
                    $granted++;

                    continue;
                }

                DB::transaction(function () use ($user, $team): void {
                    $previous = getPermissionsTeamId();
                    setPermissionsTeamId($team->id);
                    try {
                        $user->unsetRelation('roles')->unsetRelation('permissions');
                        $user->givePermissionTo(PermissionCatalog::PROGRAMS_SUPERVISE);
                    } finally {
                        setPermissionsTeamId($previous);
                        $user->unsetRelation('roles')->unsetRelation('permissions');
                    }
                });
                $granted++;
            } catch (Throwable $e) {
                $errors[] = "program_id={$row->program_id} user_id={$row->user_id}: ".$e->getMessage();
            }
        }

        if (! $dryRun && $granted > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return [
            'pivot_rows' => $pivotRows,
            'granted' => $granted,
            'already_had' => $alreadyHad,
            'skipped_missing' => $skippedMissing,
            'dry_run' => $dryRun,
            'errors' => $errors,
            'table_missing' => false,
        ];
    }

    private function userAlreadyHasProgramTeamSuperviseDirect(User $user, RbacTeam $team): bool
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($team->id);
        try {
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->getDirectPermissions()
                ->pluck('name')
                ->contains(PermissionCatalog::PROGRAMS_SUPERVISE);
        } finally {
            setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
