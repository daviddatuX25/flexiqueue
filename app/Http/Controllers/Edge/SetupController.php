<?php

namespace App\Http\Controllers\Edge;

use App\Http\Controllers\Controller;
use App\Services\EdgeDeviceSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Edge/Setup');
    }

    public function store(Request $request, EdgeDeviceSetupService $setupService): RedirectResponse
    {
        $validated = $request->validate([
            'central_url'  => ['required', 'url', 'max:255'],
            'pairing_code' => ['required', 'string', 'size:8'],
            'sync_mode'    => ['required', 'in:auto,end_of_event'],
        ]);

        try {
            $setupService->setup(
                $validated['central_url'],
                $validated['pairing_code'],
                $validated['sync_mode']
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['pairing_code' => $e->getMessage()]);
        }

        return redirect()->route('edge.waiting');
    }

    /**
     * Server-side proxy ping: checks whether the given central URL is reachable.
     * Called by the wizard to verify connectivity before pairing.
     */
    public function pingCheck(Request $request): JsonResponse
    {
        $url = $request->validate(['url' => ['required', 'url']])['url'];

        try {
            $response = Http::timeout(5)->get(rtrim($url, '/') . '/api/ping');

            return response()->json(['reachable' => $response->successful()]);
        } catch (\Throwable) {
            return response()->json(['reachable' => false]);
        }
    }
}
