<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationNote extends Model
{
    protected $fillable = ['station_id', 'message', 'updated_by'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
