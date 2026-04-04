<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surrogate IDs for Spatie `team_id` — never use raw site_id/program_id as team_id.
 *
 * @see docs/architecture/PERMISSIONS-TEAMS-AND-UI.md
 */
class RbacTeam extends Model
{
    /** Aligns with Spatie add_teams migration default pivot team_id for global scope. */
    public const GLOBAL_TEAM_ID = 1;

    protected $fillable = [
        'type',
        'site_id',
        'program_id',
        'name',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public static function forSite(Site $site): self
    {
        return static::query()->firstOrCreate(
            [
                'type' => 'site',
                'site_id' => $site->id,
            ],
            [
                'program_id' => null,
                'name' => $site->name,
            ]
        );
    }

    public static function forProgram(Program $program): self
    {
        return static::query()->firstOrCreate(
            [
                'type' => 'program',
                'program_id' => $program->id,
            ],
            [
                'site_id' => null,
                'name' => $program->name,
            ]
        );
    }

    public static function globalTeam(): self
    {
        return static::query()->findOrFail(self::GLOBAL_TEAM_ID);
    }
}
