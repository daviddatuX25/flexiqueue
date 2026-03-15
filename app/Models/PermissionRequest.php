<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Permission request: staff requests override/force-complete; supervisor/admin approves or rejects.
 */
class PermissionRequest extends Model
{
    protected $fillable = [
        'session_id',
        'action_type',
        'requester_user_id',
        'status',
        'request_token',
        'target_station_id', // deprecated: use target_track_id + custom_steps (TRACK-OVERRIDES-REFACTOR)
        'target_track_id',
        'custom_steps',
        'reason',
        'responded_by_user_id',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'responded_at' => 'datetime',
            'custom_steps' => 'array',
        ];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTION_OVERRIDE = 'override';

    public const ACTION_FORCE_COMPLETE = 'force_complete';

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function targetStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'target_station_id');
    }

    public function targetTrack(): BelongsTo
    {
        return $this->belongsTo(ServiceTrack::class, 'target_track_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
