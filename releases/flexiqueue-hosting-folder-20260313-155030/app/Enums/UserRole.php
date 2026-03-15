<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';

    /**
     * Supervisor is no longer a role; it is a program-specific permission (program_supervisors table).
     * Use User::isSupervisorForProgram($programId) or User::isSupervisorForAnyProgram() instead.
     */
}
