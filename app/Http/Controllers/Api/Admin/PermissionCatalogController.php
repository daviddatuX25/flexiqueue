<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Lists assignable permission names for admin UI (direct grants).
 */
class PermissionCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'permissions' => PermissionCatalog::assignableDirect(),
        ]);
    }
}
