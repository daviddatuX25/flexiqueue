<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeDevice extends Model
{
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
}
