<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Program extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
        'is_paused',
        'settings',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_paused' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function getNoShowTimerSeconds(): int
    {
        return (int) ($this->settings['no_show_timer_seconds'] ?? 10);
    }

    public function getRequirePermissionBeforeOverride(): bool
    {
        return (bool) ($this->settings['require_permission_before_override'] ?? true);
    }

    public function getPriorityFirst(): bool
    {
        return (bool) ($this->settings['priority_first'] ?? true);
    }

    public function getBalanceMode(): string
    {
        $mode = $this->settings['balance_mode'] ?? 'fifo';
        return in_array($mode, ['fifo', 'alternate'], true) ? $mode : 'fifo';
    }

    /**
     * @return array{0: int, 1: int} [priority_count, regular_count] e.g. [2, 1] = 2 priority per 1 regular
     */
    public function getAlternateRatio(): array
    {
        $ratio = $this->settings['alternate_ratio'] ?? [1, 1];
        if (! is_array($ratio) || count($ratio) < 2) {
            return [1, 1];
        }
        $p = max(1, (int) ($ratio[0] ?? 1));
        $r = max(1, (int) ($ratio[1] ?? 1));

        return [$p, $r];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function serviceTracks(): HasMany
    {
        return $this->hasMany(ServiceTrack::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class);
    }

    public function queueSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'program_id');
    }

    public function supervisedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'program_supervisors')
            ->withTimestamps();
    }

    public function stationAssignments(): HasMany
    {
        return $this->hasMany(ProgramStationAssignment::class, 'program_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
