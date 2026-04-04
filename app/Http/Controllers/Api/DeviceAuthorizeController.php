<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeviceAuthorizeRequest;
use App\Models\DeviceAuthorization;
use App\Models\Program;
use App\Models\User;
use App\Services\DeviceAuthorizationService;
use App\Services\PinService;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Per plan Step 5: Public device authorization. Supervisor PIN or QR verifies that this device
 * may use the program's display/triage. Session-scoped = valid while program is active; persistent = until revoked.
 */
class DeviceAuthorizeController extends Controller
{
    public function __construct(
        private DeviceAuthorizationService $deviceAuth,
        private PinService $pinService
    ) {}

    /**
     * POST /api/public/device-authorize
     * Body: program_id, pin (or qr_scan_token), allow_persistent?, device_key?
     * Returns 200 with Set-Cookie. Cookie name = device_auth_{program_id}.
     */
    public function store(DeviceAuthorizeRequest $request): JsonResponse
    {
        $program = Program::with('site')->find($request->validated('program_id'));
        if (! $program || ! $program->is_active) {
            return response()->json(['message' => 'Program not found or not active.'], 400);
        }

        $site = $program->site;
        if (! $site) {
            return response()->json(['message' => 'Program has no site.'], 400);
        }

        $verified = $this->verifyPinOrQr($request, $program);
        if (! $verified) {
            return response()->json(['message' => 'Invalid PIN or QR code.'], 401);
        }

        $authorizer = User::find((int) $verified['user_id']);
        if (! $authorizer || ! $authorizer->can(PermissionCatalog::PUBLIC_DEVICE_AUTHORIZE)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $deviceKey = $request->validated('device_key');
        if (! is_string($deviceKey) || trim($deviceKey) === '') {
            $deviceKey = Str::uuid()->toString();
        }

        $scope = $request->boolean('allow_persistent')
            ? DeviceAuthorization::SCOPE_PERSISTENT
            : DeviceAuthorization::SCOPE_SESSION;

        $result = $this->deviceAuth->authorize($program, $deviceKey, $scope);

        $cookieName = DeviceAuthorizationService::cookieNameForProgram($program);
        $cookie = Cookie::create($cookieName)
            ->withValue($result['cookie_value'])
            ->withExpires(now()->addDays(365))
            ->withPath('/')
            ->withSecure($request->secure())
            ->withHttpOnly(true)
            ->withSameSite('lax');

        $response = response()->json([
            'message' => 'Device authorized.',
            'scope' => $scope,
            'device_key' => $deviceKey,
        ]);

        return $response->cookie($cookie);
    }

    private function verifyPinOrQr(DeviceAuthorizeRequest $request, Program $program): ?array
    {
        $pin = $request->input('pin');
        $qr = $request->input('qr_scan_token');
        $hasPin = is_string($pin) && trim($pin) !== '';
        $hasQr = is_string($qr) && trim($qr) !== '';

        if ($hasPin) {
            return $this->pinService->validatePinForProgram($program->id, trim($pin));
        }
        if ($hasQr) {
            return $this->pinService->validateQrForProgram($program->id, trim($qr));
        }

        return null;
    }
}
