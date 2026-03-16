<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIdAuditLog;
use App\Services\MobileCryptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAdminController extends Controller
{
    public function update(Request $request, Client $client, MobileCryptoService $mobileCrypto): JsonResponse
    {
        $user = $request->user();
        if ($user->site_id === null || $client->site_id !== $user->site_id) {
            abort(404);
        }

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $client->update($data);

        $mobileMasked = $client->mobile_encrypted
            ? $mobileCrypto->mask($mobileCrypto->decrypt($client->mobile_encrypted))
            : null;

        return response()->json([
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'middle_name' => $client->middle_name,
                'last_name' => $client->last_name,
                'birth_date' => $client->birth_date?->format('Y-m-d'),
                'address_line_1' => $client->address_line_1,
                'address_line_2' => $client->address_line_2,
                'city' => $client->city,
                'state' => $client->state,
                'postal_code' => $client->postal_code,
                'country' => $client->country,
                'mobile_masked' => $mobileMasked,
                'created_at' => $client->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();
        if ($user->site_id === null || $client->site_id !== $user->site_id) {
            abort(404);
        }

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

