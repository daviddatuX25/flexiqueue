<?php

namespace App\Services\Tts;

use Carbon\Carbon;

/**
 * Period keying for TTS budget (YYYY-MM-DD daily, YYYY-MM monthly).
 */
class TtsPeriodKey
{
    public static function forNow(string $period): string
    {
        return self::forDate(Carbon::now(), $period);
    }

    public static function forDate(Carbon $date, string $period): string
    {
        return $period === 'daily'
            ? $date->format('Y-m-d')
            : $date->format('Y-m');
    }
}
