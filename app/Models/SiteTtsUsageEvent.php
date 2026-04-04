<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only TTS generation usage event for metering.
 * Recorded on every successful synthesis (jobs + preview).
 */
class SiteTtsUsageEvent extends Model
{
    public const SOURCE_JOB = 'job';

    public const SOURCE_PREVIEW = 'preview';

    protected $fillable = [
        'site_id',
        'provider',
        'chars_used',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'chars_used' => 'integer',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
