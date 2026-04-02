<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row state for an edge device (id always 1).
 * Per CI-CD-and-Edge-plan Part B: paired_at, central_url, site_id, site_name,
 * device_token, sync_mode, supervisor_admin_access, active_program_id,
 * active_program_name, session_active, last_synced_at.
 */
class EdgeDeviceState extends Model
{
    protected $table = 'edge_device_state';

    protected $fillable = [
        'paired_at',
        'central_url',
        'site_id',
        'site_name',
        'device_token',
        'sync_mode',
        'supervisor_admin_access',
        'active_program_id',
        'active_program_name',
        'session_active',
        'last_synced_at',
        'id_offset',
        'app_version',
        'package_version',
    ];

    protected function casts(): array
    {
        return [
            'paired_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'supervisor_admin_access' => 'boolean',
            'session_active' => 'boolean',
            'device_token' => 'encrypted',
            'id_offset' => 'integer',
        ];
    }

    /**
     * Return the single row (id=1), or create it with defaults if missing.
     */
    public static function current(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'sync_mode' => 'auto',
                'supervisor_admin_access' => false,
                'session_active' => false,
            ]
        );
    }
}
