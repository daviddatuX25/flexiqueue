<?php

namespace App\Console\Commands;

use App\Services\ProgramSupervisorPivotToProgramTeamSyncService;
use Illuminate\Console\Command;

/**
 * R3: Mirror `program_supervisors` into program-scoped Spatie direct permission `programs.supervise`.
 */
class SyncProgramSupervisorsToProgramTeamsCommand extends Command
{
    protected $signature = 'rbac:sync-supervisor-pivot-to-program-teams
                            {--dry-run : Report counts only; do not write to the database}';

    protected $description = 'Grant programs.supervise on each program RbacTeam for every program_supervisors row (idempotent; use --dry-run on staging first)';

    public function handle(ProgramSupervisorPivotToProgramTeamSyncService $syncService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $syncService->sync($dryRun);

        if (! empty($result['table_missing'])) {
            $this->info('program_supervisors table is not present (R4 complete). Nothing to sync.');

            return self::SUCCESS;
        }

        $this->info('Pivot rows scanned: '.$result['pivot_rows']);
        if ($dryRun) {
            $this->line('Would grant (no DB writes): '.$result['granted']);
        } else {
            $this->line('Granted: '.$result['granted']);
        }
        $this->line('Already had program-team supervise: '.$result['already_had']);
        if ($result['skipped_missing'] > 0) {
            $this->warn('Skipped (missing program or user): '.$result['skipped_missing']);
        }

        foreach ($result['errors'] as $err) {
            $this->error($err);
        }

        if (! empty($result['errors'])) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
