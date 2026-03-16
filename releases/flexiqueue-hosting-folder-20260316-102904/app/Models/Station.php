<?php

namespace App\Models;

use App\Events\StationDeleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = [
        'program_id',
        'name',
        'capacity',
        'client_capacity',
        'holding_capacity',
        'priority_first_override',
        'settings',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority_first_override' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** Per plan: station display TTS mute (controlled from staff /station/*). Default false. */
    public function getDisplayAudioMuted(): bool
    {
        return (bool) ($this->settings['display_audio_muted'] ?? false);
    }

    /** Per plan: station display TTS volume 0–1 (controlled from staff /station/*). Default 1. */
    public function getDisplayAudioVolume(): float
    {
        $v = $this->settings['display_audio_volume'] ?? 1;

        return (float) max(0, min(1, $v));
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * Per PROCESS-STATION-REFACTOR: Station M:M Process. Every station must have at least one process.
     */
    public function processes(): BelongsToMany
    {
        return $this->belongsToMany(Process::class, 'station_process')
            ->withTimestamps();
    }

    public function trackSteps(): HasMany
    {
        return $this->hasMany(TrackStep::class);
    }

    public function queueSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'current_station_id');
    }

    public function assignedStaff(): HasMany
    {
        return $this->hasMany(User::class, 'assigned_station_id');
    }

    public function note(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StationNote::class);
    }

    public function getHoldingCapacity(): int
    {
        $capacity = $this->holding_capacity ?? 3;

        return (int) max(0, min(255, $capacity));
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $station) => event(new StationDeleted($station)));
    }
}
