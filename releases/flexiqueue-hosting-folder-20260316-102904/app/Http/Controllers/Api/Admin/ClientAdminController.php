<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIdAuditLog;
use Illuminate\Http\JsonResponse;

class ClientAdminController extends Controller
{
    public function destroy(Client $client): JsonResponse
    {
        $hasAuditLog = ClientIdAuditLog::query()
            ->where('client_id', $client->id)
            ->exists();

        if ($hasAuditLog) {
            return response()->json([
                'message' => 'Cannot delete client: audit log exists for this client.',
            ], 409);
        }

        $client->delete();

        return response()->json(['deleted' => true]);
    }
}

