<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Token extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'physical_id',
        'status',
        'current_session_id',
    ];

    /**
     * qr_code_hash is immutable after creation (per 04-DATA-MODEL).
     * Set directly when creating: $token->qr_code_hash = $hash; $token->save();
     */
    protected $guarded = ['qr_code_hash'];

    public function currentSession(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'current_session_id');
    }

    public function queueSessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
