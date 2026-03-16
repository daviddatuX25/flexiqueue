<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Program-specific supervisor assignment.
 * Per refactor plan: supervisor is a permission per program, not a global role.
 */
class ProgramSupervisor extends Model
{
    protected $table = 'program_supervisors';

    protected $fillable = ['program_id', 'user_id'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
