<?php

namespace App\Observers;

use App\Models\Program;
use App\Models\RbacTeam;

class ProgramObserver
{
    public function created(Program $program): void
    {
        RbacTeam::forProgram($program);
    }
}
