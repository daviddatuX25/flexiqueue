<?php

namespace App\Http\Controllers;

use App\Models\PermissionRequest;
use App\Models\Program;
use App\Models\Station;
use App\Models\TemporaryAuthorization;
use App\Services\StationQueueService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Track Overrides page: Generate PIN/QR, view and manage permission requests.
 * Per TRACK-OVERRIDES-REFACTOR: tracks, authorizations list, target_track.
 */
class AuthorizationPageController extends Controller
{
    public function __construct(
        private StationQueueService $stationQueueService
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canApprove = $user->isAdmin() || $user->isSupervisorForAnyProgram();

        $program = Program::where('is_active', true)->first();
        $footerStats = $this->stationQueueService->getProgramFooterStats($program);
        $stations = [];
        $tracks = [];
        if ($program) {
            $stations = $program->stations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Station $s) => ['id' => $s->id, 'name' => $s->name])
                ->values()
                ->all();
            $tracks = $program->serviceTracks()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])
                ->values()
                ->all();
        }

        $authorizations = [];
        if ($canApprove) {
            $authorizations = TemporaryAuthorization::where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'type', 'created_at', 'expires_at', 'used_at'])
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'type' => $a->type,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'expires_at' => $a->expires_at?->toIso8601String(),
                    'used_at' => $a->used_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $query = PermissionRequest::query()
            ->with(['session.serviceTrack', 'session.currentStation', 'requester', 'targetStation', 'targetTrack'])
            ->where('status', 'pending')
            ->orderByDesc('created_at');

        if (! $canApprove) {
            $query->where('requester_user_id', $user->id);
        }

        $pendingRequests = $query->limit(50)->get()->map(fn (PermissionRequest $pr) => [
            'id' => $pr->id,
            'session_id' => $pr->session_id,
            'action_type' => $pr->action_type,
            'reason' => $pr->reason,
            'created_at' => $pr->created_at->toIso8601String(),
            'session' => [
                'id' => $pr->session->id,
                'alias' => $pr->session->alias,
                'status' => $pr->session->status,
                'track' => $pr->session->serviceTrack?->name ?? '—',
                'current_station' => $pr->session->currentStation ? ['id' => $pr->session->currentStation->id, 'name' => $pr->session->currentStation->name] : null,
            ],
            'requester' => ['id' => $pr->requester->id, 'name' => $pr->requester->name],
            'target_station' => $pr->targetStation ? ['id' => $pr->targetStation->id, 'name' => $pr->targetStation->name] : null,
            'target_track' => $pr->targetTrack ? ['id' => $pr->targetTrack->id, 'name' => $pr->targetTrack->name] : null,
        ])->values()->all();

        return Inertia::render('TrackOverrides/Index', [
            'canApprove' => $canApprove,
            'stations' => $stations,
            'tracks' => $tracks,
            'authorizations' => $authorizations,
            'pendingRequests' => $pendingRequests,
            'queueCount' => $footerStats['queue_count'],
            'processedToday' => $footerStats['processed_today'],
        ]);
    }
}
