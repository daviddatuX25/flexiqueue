<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisplaySettingsRequest extends Model
{
    protected $fillable = [
        'program_id',
        'request_token',
        'status',
        'settings_payload',
        'responded_by_user_id',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'settings_payload' => 'array',
            'responded_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
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
