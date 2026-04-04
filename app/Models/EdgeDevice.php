<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdgeDevice extends Model
{
    use HasFactory;

    /** @var array<int, string> Non-persisted plain tokens keyed by model ID */
    protected static array $plainTokens = [];

    /** @var string|null Temporary plain token set during fill(), consumed in created() */
    protected ?string $_pendingPlainToken = null;

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

    protected static function booted(): void
    {
        static::created(function (EdgeDevice $device): void {
            if ($device->_pendingPlainToken !== null) {
                self::$plainTokens[$device->id] = $device->_pendingPlainToken;
                $device->_pendingPlainToken = null;
            }
        });
    }

    /**
     * Intercept _plain_token during mass-assignment. It is NOT a DB column —
     * stored temporarily in $_pendingPlainToken, then moved to static
     * $plainTokens[$id] in the created event where the real ID is available.
     */
    public function fill(array $attributes): static
    {
        if (isset($attributes['_plain_token'])) {
            $this->_pendingPlainToken = $attributes['_plain_token'];
            unset($attributes['_plain_token']);
        }

        return parent::fill($attributes);
    }

    /**
     * Never persist _plain_token to the database.
     */
    public function getAttributes(): array
    {
        $attributes = parent::getAttributes();
        unset($attributes['_plain_token']);

        return $attributes;
    }

    /**
     * Accessor for _plain_token — retrieves from static storage by model ID.
     * Falls back to _pendingPlainToken for newly-created (but not yet saved) models.
     */
    public function getPlainTokenAttribute(): ?string
    {
        if ($this->id !== null) {
            return self::$plainTokens[$this->id] ?? null;
        }

        return $this->_pendingPlainToken;
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
