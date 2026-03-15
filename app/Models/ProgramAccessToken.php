<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per addition-to-public-site-plan Part 2.3: temporary token for private program access.
 * Token is hashed (SHA-256); raw token is only returned once on issue.
 */
class ProgramAccessToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'program_id',
        'token_hash',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
