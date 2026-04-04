<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

/**
 * R3 command: after R4 pivot removal, `rbac:sync-supervisor-pivot-to-program-teams` is a no-op.
 */
class SyncProgramSupervisorsToProgramTeamsCommandTest extends TestCase
{
    public function test_command_completes_when_pivot_table_removed(): void
    {
        $this->artisan('rbac:sync-supervisor-pivot-to-program-teams')
            ->expectsOutputToContain('program_supervisors table is not present')
            ->assertSuccessful();
    }
}
