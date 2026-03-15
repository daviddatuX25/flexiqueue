<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per plan Step 5: Device authorization for public display/triage.
 * After supervisor PIN/QR verification, a device (identified by cookie) is allowed
 * to use a program. Scope: session = valid only while program is active; persistent = until revoked or program deleted.
 */
class DeviceAuthorization extends Model
{
    public const SCOPE_SESSION = 'session';

    public const SCOPE_PERSISTENT = 'persistent';

    protected $fillable = [
        'program_id',
        'device_key_hash',
        'scope',
        'cookie_token_hash',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
