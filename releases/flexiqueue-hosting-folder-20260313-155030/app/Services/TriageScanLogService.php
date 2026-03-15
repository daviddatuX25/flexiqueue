<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Logs triage scan attempts to triage_scan_log.
 * Per REFACTORING-ISSUE-LIST.md Issue 2: moved from SessionController::logTriageScan().
 * Per REFACTORING-ISSUE-LIST.md Issue 3: no schema checks at runtime; rely on migrations.
 * Per ISSUES-ELABORATION §11: result not_found flags potentially fabricated/invalid scans.
 */
class TriageScanLogService
{
    /**
     * Log a triage scan attempt. Swallows and logs insert failures so callers are unaffected.
     */
    public function log(Request $request, ?int $tokenId, string $result, ?string $physicalId, ?string $qrHash): void
    {
        try {
            DB::table('triage_scan_log')->insert([
                'physical_id' => $physicalId ?? $request->query('physical_id'),
                'qr_hash' => $qrHash ?? $request->query('qr_hash'),
                'result' => $result,
                'token_id' => $tokenId,
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Triage scan log insert failed', ['exception' => $e]);
        }
    }
}
