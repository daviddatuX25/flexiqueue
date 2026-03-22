<?php

namespace App\Console\Commands;

use App\Models\Program;
use App\Models\RbacTeam;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Idempotent: ensure every site and program has an rbac_teams row.
 */
class RbacSyncTeamsCommand extends Command
{
    protected $signature = 'rbac:sync-teams';

    protected $description = 'Create RbacTeam rows for all sites and programs (Phase 6)';

    public function handle(): int
    {
        $sites = 0;
        foreach (Site::query()->cursor() as $site) {
            RbacTeam::forSite($site);
            $sites++;
        }

        $programs = 0;
        foreach (Program::query()->cursor() as $program) {
            RbacTeam::forProgram($program);
            $programs++;
        }

        $this->info("Synced RbacTeam for {$sites} site(s) and {$programs} program(s).");

        return self::SUCCESS;
    }
}
