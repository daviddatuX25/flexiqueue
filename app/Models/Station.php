<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    protected $fillable = [
        'program_id',
        'name',
        'capacity',
        'client_capacity',
        'priority_first_override',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority_first_override' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
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
}
