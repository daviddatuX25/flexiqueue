<?php

namespace App\Http\Middleware;

use App\Models\EdgeDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEdgeDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device = EdgeDevice::where('device_token_hash', hash('sha256', $token))->first();

        if (! $device) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->attributes->set('edge_device', $device);

        return $next($request);
    }
}
