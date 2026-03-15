<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per addition-to-public-site-plan Part 2.4 + Addition A.1: opaque short link for QR codes.
 * type: site_entry | program_public | program_private.
 * embedded_key: encrypted program key for scannable private QR (nullable).
 */
class SiteShortLink extends Model
{
    public const TYPE_SITE_ENTRY = 'site_entry';
    public const TYPE_PROGRAM_PUBLIC = 'program_public';
    public const TYPE_PROGRAM_PRIVATE = 'program_private';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'type',
        'site_id',
        'program_id',
        'embedded_key',
    ];

    protected function casts(): array
    {
        return [
            'embedded_key' => 'encrypted',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
