<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case SuperAdmin = 'super_admin';

    /**
     * Supervisor is not a role; use User::isSupervisorForProgram / isSupervisorForAnyProgram
     * (Spatie `programs.supervise` on the program RbacTeam.)
     */
}
