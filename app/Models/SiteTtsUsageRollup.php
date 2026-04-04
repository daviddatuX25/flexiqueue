<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aggregated TTS usage per site per period for fast budget checks.
 */
class SiteTtsUsageRollup extends Model
{
    protected $fillable = [
        'site_id',
        'period_key',
        'chars_used',
        'generation_count',
    ];

    protected function casts(): array
    {
        return [
            'chars_used' => 'integer',
            'generation_count' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
