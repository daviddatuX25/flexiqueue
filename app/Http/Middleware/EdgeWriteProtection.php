<?php
namespace App\Http\Middleware;

use App\Services\EdgeModeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EdgeWriteProtection
{
  private const EDGE_ALLOWED = [
    'api/admin/edge/*',
    'api/admin/edge-devices/*',
    'api/admin/sites/*/edge-devices*',
    'api/admin/programs/*/activate',
    'api/admin/programs/*/deactivate',
    'api/admin/programs/*/pause',
    'api/admin/programs/*/resume',
  ];

  public function __construct(private EdgeModeService $edgeModeService) {}

  public function handle(Request $request, Closure $next): Response
  {
    if (! $this->edgeModeService->isEdge()) {
      return $next($request);
    }

    if (! $request->is('api/admin/*')) {
      return $next($request);
    }

    if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
      return $next($request);
    }

    foreach (self::EDGE_ALLOWED as $pattern) {
      if ($request->is($pattern)) {
        return $next($request);
      }
    }

    return response()->json([
      'message' => 'This action is not available in Edge Mode. Make the change on the central server, then re-sync the package.',
    ], 403);
  }
}
