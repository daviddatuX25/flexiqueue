<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTrack extends Model
{
    protected $fillable = [
        'program_id',
        'name',
        'description',
        'is_default',
        'color_code',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function trackSteps(): HasMany
    {
        return $this->hasMany(TrackStep::class, 'track_id')->orderBy('step_order');
    }

    public function queueSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'track_id');
    }
}
