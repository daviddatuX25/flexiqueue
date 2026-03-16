<?php

namespace Database\Seeders\Shared;

use Illuminate\Support\Facades\DB;

/**
 * Shared static methods for Central and Edge history seeders.
 * Per docs/seeder-plan.txt §2.
 */
class HistoryHelper
{
    /**
     * Convert 0-based index to physical ID. 0–8 = A1–A9, 9–17 = B1–B9, etc.
     */
    public static function makeTokenPhysicalId(int $index): string
    {
        $letter = chr(65 + (int) intdiv($index, 9));
        $number = ($index % 9) + 1;

        return $letter . $number;
    }

    /**
     * Return hash for token QR code. Per plan: TOKEN_{siteSlug}_{physicalId}.
     */
    public static function makeQrHash(string $siteSlug, string $physicalId): string
    {
        return hash('sha256', 'TOKEN_' . $siteSlug . '_' . $physicalId);
    }

    /**
     * Return $count Carbon-like timestamps spread across 8:00 AM–4:30 PM with morning peak.
     * 50% morning (8–11), 35% afternoon (1–3), 15% late (3–4:30). Sorted ascending.
     *
     * @param  int  $count
     * @return array<\Carbon\Carbon>
     */
    public static function spreadSessionsAcrossDay(int $count, \Carbon\Carbon $baseDate): array
    {
        $morning = (int) round($count * 0.50);
        $afternoon = (int) round($count * 0.35);
        $lateAfternoon = $count - $morning - $afternoon;

        $times = [];
        for ($i = 0; $i < $morning; $i++) {
            $times[] = $baseDate->copy()->setTime(8, 0)->addMinutes(rand(0, 179));
        }
        for ($i = 0; $i < $afternoon; $i++) {
            $times[] = $baseDate->copy()->setTime(13, 0)->addMinutes(rand(0, 119));
        }
        for ($i = 0; $i < $lateAfternoon; $i++) {
            $times[] = $baseDate->copy()->setTime(15, 0)->addMinutes(rand(0, 89));
        }
        usort($times, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $times;
    }

    /**
     * Category from track name. Priority → Senior Citizen 60% / PWD 40%. Regular → Regular.
     */
    public static function pickCategory(string $trackName): string
    {
        if (str_contains($trackName, 'Priority')) {
            return rand(1, 100) <= 60 ? 'Senior Citizen' : 'PWD';
        }

        return 'Regular';
    }

    /**
     * Weighted outcome: 75% completed, 10% no_show, 15% cancelled.
     */
    public static function pickOutcome(int $dayIndex): string
    {
        $roll = rand(1, 100);
        if ($roll <= 75) {
            return 'completed';
        }
        if ($roll <= 85) {
            return 'no_show';
        }

        return 'cancelled';
    }

    /**
     * Insert one transaction_logs row. staff_user_id can be null for public bind.
     */
    public static function insertLog(
        int $sessionId,
        ?int $stationId,
        ?int $staffId,
        string $actionType,
        ?int $prevStationId,
        ?int $nextStationId,
        string $createdAt
    ): void {
        DB::table('transaction_logs')->insert([
            'session_id' => $sessionId,
            'station_id' => $stationId,
            'staff_user_id' => $staffId,
            'action_type' => $actionType,
            'previous_station_id' => $prevStationId,
            'next_station_id' => $nextStationId,
            'remarks' => null,
            'metadata' => null,
            'created_at' => $createdAt,
        ]);
    }
}
