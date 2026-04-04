<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeDevice extends Model
{
    use HasFactory;
    protected $fillable = [
        'site_id',
        'name',
        'device_token_hash',
        'id_offset',
        'sync_mode',
        'supervisor_admin_access',
        'assigned_program_id',
        'session_active',
        'app_version',
        'last_seen_at',
        'last_synced_at',
        'paired_at',
        'revoked_at',
        'force_cancelled_at',
        'update_status',
        'dump_requested',
    ];

    protected function casts(): array
    {
        return [
            'supervisor_admin_access' => 'boolean',
            'session_active' => 'boolean',
            'id_offset' => 'integer',
            'paired_at' => 'datetime',
            'revoked_at' => 'datetime',
            'force_cancelled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'dump_requested' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function assignedProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'assigned_program_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Compute display status for the admin UI.
     * online  — seen within 6 min, session active
     * waiting — seen within 60 min, no assigned program
     * idle    — seen within 60 min, has program, no active session
     * stale   — not seen for >60 min but has assigned program
     * offline — not seen for >60 min and no program, or never seen
     */
    public function getStatus(): string
    {
        if (! $this->last_seen_at) {
            return $this->assigned_program_id ? 'stale' : 'offline';
        }

        $minutesAgo = (int) $this->last_seen_at->diffInMinutes(now());

        if ($minutesAgo <= 6) {
            if ($this->session_active) {
                return 'online';
            }
            return $this->assigned_program_id ? 'idle' : 'waiting';
        }

        if ($minutesAgo <= 60) {
            return $this->assigned_program_id ? 'idle' : 'waiting';
        }

        return $this->assigned_program_id ? 'stale' : 'offline';
    }
}
