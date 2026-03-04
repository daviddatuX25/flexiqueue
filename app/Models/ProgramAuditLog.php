<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail for program session start/stop (activate/deactivate).
 * Per flexiqueue-loo; separate from transaction_logs which is session-scoped.
 */
class ProgramAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'program_audit_log';

    protected $fillable = [
        'program_id',
        'staff_user_id',
        'action',
        'created_at',
    ];

    /**
     * Set created_at on create (table has NOT NULL / useCurrent; ensure set on SQLite).
     */
    protected static function booted(): void
    {
        static::creating(function (ProgramAuditLog $log): void {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
