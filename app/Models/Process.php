<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Per PROCESS-STATION-REFACTOR: Logical work type. Stations M:M with processes.
 */
class Process extends Model
{
    protected $fillable = [
        'program_id',
        'name',
        'description',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class, 'station_process')
            ->withTimestamps();
    }

    public function trackSteps(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TrackStep::class, 'process_id');
    }

    /**
     * Active stations that serve this process.
     */
    /**
     * Stations that serve this process and are active.
     */
    public function activeStations(): BelongsToMany
    {
        return $this->stations()->where('is_active', true);
    }
}
