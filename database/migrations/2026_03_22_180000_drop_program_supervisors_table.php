<?php

use App\Models\RbacTeam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * R4: Supervision is expressed only as `programs.supervise` on program {@see RbacTeam}
 * (see ProgramSupervisorGrantService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('program_supervisors');
    }

    public function down(): void
    {
        // Pivot removed by design; restoring would duplicate historical migration 2025_02_15_000006.
    }
};
