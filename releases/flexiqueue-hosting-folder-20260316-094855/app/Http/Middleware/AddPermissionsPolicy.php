<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddPermissionsPolicy
{
    /**
     * Add Permissions-Policy header to allow camera for QR scanner (mobile).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Permissions-Policy', 'camera=(self)');

        return $response;
    }
}
