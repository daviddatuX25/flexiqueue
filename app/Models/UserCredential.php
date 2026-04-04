<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §4.1: local (username + hash) and future google rows per user.
 */
class UserCredential extends Model
{
    public const PROVIDER_LOCAL = 'local';

    public const PROVIDER_GOOGLE = 'google';

    protected $table = 'user_credentials';

    protected $fillable = [
        'user_id',
        'provider',
        'identifier',
        'secret',
    ];

    protected $hidden = [
        'secret',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
