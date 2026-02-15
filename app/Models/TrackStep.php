<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackStep extends Model
{
    protected $fillable = [
        'track_id',
        'station_id',
        'step_order',
        'is_required',
        'estimated_minutes',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    public function serviceTrack(): BelongsTo
    {
        return $this->belongsTo(ServiceTrack::class, 'track_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
