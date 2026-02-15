<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-program station assignment. Staff can have different stations per program.
 */
class ProgramStationAssignment extends Model
{
    protected $table = 'program_station_assignments';

    protected $fillable = ['program_id', 'user_id', 'station_id'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
