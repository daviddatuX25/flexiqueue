<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Per SUPER-ADMIN-VS-ADMIN-SPEC: log of admin-level actions (user/site create, update, delete).
 * Super_admin audit view shows only these entries.
 */
class AdminActionLog extends Model
{
    public $timestamps = false;

    protected $table = 'admin_action_log';

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an admin action. Call from controllers after successful user/site create, update, delete.
     */
    public static function log(int $userId, string $action, string $subjectType, ?int $subjectId = null, array $payload = []): self
    {
        $log = new self([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload,
            'created_at' => now(),
        ]);
        $log->save();

        return $log;
    }
}
