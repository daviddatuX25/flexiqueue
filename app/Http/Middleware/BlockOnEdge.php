<?php

namespace App\Http\Middleware;

use App\Services\EdgeModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockOnEdge
{
    public function __construct(private EdgeModeService $edgeModeService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->edgeModeService->isEdge()) {
            abort(404);
        }

        return $next($request);
    }
}
