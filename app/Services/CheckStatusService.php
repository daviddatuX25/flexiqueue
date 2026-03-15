<?php

namespace App\Services;

use App\Models\Token;
use Illuminate\Support\Collection;

/**
 * Per 08-API-SPEC-PHASE1 §2.1: Token status for client QR check.
 * No internal IDs exposed (per 05-SECURITY-CONTROLS).
 */
class CheckStatusService
{
    /**
     * Get status for a token by qr_code_hash. Optionally scope by site_id so tokens are unambiguous across sites.
     *
     * @param  int|null  $siteId  When set, token must belong to this site (prevents cross-site recognition).
     * @return array{result: 'not_found'|'unavailable'|'available'|'in_use', error?: string, alias?: string, status?: string, ...}
     */
    public function getStatus(string $qrHash, ?int $siteId = null): array
    {
        $query = Token::where('qr_code_hash', $qrHash);
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }
        $token = $query->first();

        if (! $token) {
            return [
                'result' => 'not_found',
                'error' => 'Token not found.',
            ];
        }

        // Soft-deleted tokens excluded by Token model scope; token not found above.

        if ($token->status !== 'in_use' || ! $token->currentSession) {
            return [
                'result' => 'available',
                'alias' => $token->physical_id,
                'status' => 'available',
                'message' => 'This token is not currently in use.',
            ];
        }

        $session = $token->currentSession;
        $session->load(['serviceTrack.trackSteps.process', 'serviceTrack.trackSteps.station', 'currentStation']);
        $track = $session->serviceTrack;
        $steps = $track ? $track->trackSteps->sortBy('step_order') : collect();
        $currentOrder = (int) ($session->current_step_order ?? 1);
        $progressSteps = $this->buildProgressSteps($steps, $currentOrder);
        $estimatedWaitMinutes = $this->computeEstimatedWaitMinutes($steps, $currentOrder);

        return [
            'result' => 'in_use',
            'alias' => $session->alias,
            'track' => $track?->name ?? '—',
            'track_id' => $track?->id,
            'program_id' => $session->program_id,
            'client_category' => $session->client_category ?? 'Regular',
            'status' => $session->status,
            'current_station' => $session->currentStation?->name ?? '—',
            'progress' => [
                'total_steps' => count($progressSteps),
                'current_step' => $currentOrder,
                'steps' => $progressSteps,
            ],
            'estimated_wait_minutes' => $estimatedWaitMinutes,
            'started_at' => $session->started_at?->toIso8601String(),
        ];
    }

    /**
     * Per flexiqueue-5l7: estimated wait = sum of remaining steps' estimated_minutes;
     * if step has no estimated_minutes, use process expected_time_seconds/60.
     *
     * @param  Collection<int, \App\Models\TrackStep>  $steps
     */
    private function computeEstimatedWaitMinutes(Collection $steps, int $currentOrder): ?int
    {
        $remaining = $steps->filter(fn ($s) => (int) $s->step_order > $currentOrder);
        if ($remaining->isEmpty()) {
            return 0;
        }
        $total = 0;
        foreach ($remaining as $step) {
            $mins = $step->estimated_minutes;
            if ($mins !== null && $mins >= 0) {
                $total += $mins;
            } elseif ($step->process && $step->process->expected_time_seconds !== null) {
                $total += (int) ceil($step->process->expected_time_seconds / 60);
            }
        }

        return $total > 0 ? $total : null;
    }

    /**
     * Per PROCESS-STATION-REFACTOR: use process_name; fallback to station_name for dual-read.
     *
     * @param  Collection<int, \App\Models\TrackStep>  $steps
     * @return array<int, array{name: string, station_name: string, status: string}>
     */
    private function buildProgressSteps(Collection $steps, int $currentOrder): array
    {
        return $steps->values()->map(function ($step, $index) use ($currentOrder) {
            $order = $index + 1;
            if ($order < $currentOrder) {
                $status = 'complete';
            } elseif ($order === $currentOrder) {
                $status = 'in_progress';
            } else {
                $status = 'pending';
            }

            $displayName = $step->process?->name ?? $step->station?->name ?? 'Step '.$order;

            return [
                'name' => $displayName,
                'station_name' => $displayName,
                'status' => $status,
            ];
        })->all();
    }
}
