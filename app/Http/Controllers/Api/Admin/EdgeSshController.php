<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EdgeSshController extends Controller
{
    public function enable(): JsonResponse
    {
        if (config('app.mode') !== 'edge') {
            abort(404);
        }

        $script = (string) config('flexiqueue.edge_ssh_enable_script');

        if (! file_exists($script) || ! is_executable($script)) {
            return response()->json([
                'error' => 'SSH toggle not available on this device.',
            ], 503);
        }

        $output   = [];
        $exitCode = 0;
        exec('sudo ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            return response()->json([
                'error' => 'Failed to enable SSH: ' . implode(' ', $output),
            ], 500);
        }

        return response()->json([
            'message'    => 'SSH enabled for 30 minutes.',
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ]);
    }
}