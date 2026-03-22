<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton-style platform TTS budget when global mode is enabled (weighted split across sites).
 */
class TtsPlatformBudget extends Model
{
    protected $fillable = [
        'global_enabled',
        'period',
        'mode',
        'char_limit',
        'block_on_limit',
        'warning_threshold_pct',
    ];

    protected function casts(): array
    {
        return [
            'global_enabled' => 'boolean',
            'char_limit' => 'integer',
            'block_on_limit' => 'boolean',
            'warning_threshold_pct' => 'integer',
        ];
    }

    public static function settings(): self
    {
        $row = static::query()->orderBy('id')->first();
        if ($row !== null) {
            return $row;
        }

        return static::query()->create([
            'global_enabled' => false,
            'period' => 'monthly',
            'mode' => 'chars',
            'char_limit' => 0,
            'block_on_limit' => true,
            'warning_threshold_pct' => 80,
        ]);
    }
}
