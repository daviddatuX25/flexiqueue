<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Site;
use App\Models\Station;
use App\Support\DeviceLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per plan: set device lock cookie (after user chooses device type on choose page).
 * Returns redirect_url; response includes Set-Cookie so frontend can navigate and get the cookie.
 */
class PublicDeviceLockController extends Controller
{
    /**
     * Set device lock cookie. If request wants JSON (e.g. fetch), return 200 JSON + Set-Cookie.
     * If not (e.g. form POST), return 302 redirect + Set-Cookie so the browser follows with the cookie set.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'site_slug' => ['required', 'string', 'max:255'],
            'program_slug' => ['required', 'string', 'max:255'],
            'device_type' => ['required', 'string', 'in:display,triage,station'],
            'station_id' => ['required_if:device_type,station', 'nullable', 'integer'],
        ]);

        $devicesUrl = '/site/'.$validated['site_slug'].'/program/'.$validated['program_slug'];
        $toJsonOrRedirect = function (string $message, int $status) use ($request, $devicesUrl) {
            if (! $request->wantsJson()) {
                return redirect()->to($devicesUrl)->with('error', $message);
            }

            return response()->json(['message' => $message], $status);
        };

        $site = Site::query()->where('slug', $validated['site_slug'])->first();
        if (! $site) {
            return $toJsonOrRedirect('Site not found.', 404);
        }

        $program = Program::query()
            ->where('site_id', $site->id)
            ->where('slug', $validated['program_slug'])
            ->where('is_active', true)
            ->first();
        if (! $program) {
            return $toJsonOrRedirect('Program not found or inactive.', 404);
        }

        $deviceType = $validated['device_type'];
        $stationId = isset($validated['station_id']) ? (int) $validated['station_id'] : null;

        if ($deviceType === DeviceLock::TYPE_STATION) {
            if ($stationId === null) {
                return $toJsonOrRedirect('Station is required for station display.', 422);
            }
            $station = Station::query()
                ->where('id', $stationId)
                ->where('program_id', $program->id)
                ->where('is_active', true)
                ->first();
            if (! $station) {
                return $toJsonOrRedirect('This station is no longer available.', 422);
            }
        }

        $redirectUrl = match ($deviceType) {
            DeviceLock::TYPE_DISPLAY => '/site/'.$site->slug.'/display?program='.$program->id,
            DeviceLock::TYPE_TRIAGE => '/site/'.$site->slug.'/public-triage/'.$program->slug,
            DeviceLock::TYPE_STATION => '/site/'.$site->slug.'/display/station/'.$stationId,
        };

        $cookie = DeviceLock::encode(
            $site->slug,
            $program->slug,
            $deviceType,
            $deviceType === DeviceLock::TYPE_STATION ? $stationId : null
        );

        // Form POST: redirect with one-time lock_token; target page will consume token and set cookie (avoids cookie not sent on redirect follow-up).
        if (! $request->wantsJson()) {
            $lockToken = DeviceLock::createLockToken(
                $site->slug,
                $program->slug,
                $deviceType,
                $deviceType === DeviceLock::TYPE_STATION ? $stationId : null
            );
            $separator = str_contains($redirectUrl, '?') ? '&' : '?';
            $urlWithToken = $redirectUrl.$separator.'lock_token='.urlencode($lockToken);

            return redirect()->to($urlWithToken, 303);
        }

        return response()
            ->json(['redirect_url' => $redirectUrl], 200)
            ->cookie($cookie);
    }

    /**
     * Clear the device lock cookie and session (e.g. after unlock approved). No body. Returns 200 with Set-Cookie to clear.
     */
    public function destroy(Request $request): JsonResponse
    {
        DeviceLock::clearFromSession($request);

        return response()
            ->json(['message' => 'Lock cleared.'], 200)
            ->cookie(DeviceLock::clearCookie());
    }
}
