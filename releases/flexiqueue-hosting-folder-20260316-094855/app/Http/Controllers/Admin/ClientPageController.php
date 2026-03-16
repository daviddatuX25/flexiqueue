<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Services\ClientIdDocumentService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin client list + detail views for XM2O identity binding.
 *
 * Per xm2o plan: admins and supervisors can browse clients and see redacted IDs; reveal remains admin-only via API.
 */
class ClientPageController extends Controller
{
    public function index(Request $request): Response
    {
        $this->assertCanViewClients($request);

        $search = (string) $request->query('search', '');

        $query = Client::query()
            ->withCount('idDocuments')
            ->orderBy('created_at', 'desc');

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $clients = $query
            ->get()
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'birth_year' => $client->birth_year,
                'id_documents_count' => $client->id_documents_count,
                'created_at' => $client->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'search' => $search !== '' ? $search : null,
        ]);
    }

    public function show(Client $client, ClientIdDocumentService $idDocumentService): Response
    {
        $this->assertCanViewClients(request());

        $idDocuments = $client->idDocuments()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (ClientIdDocument $document) => [
                'id' => $document->id,
                'id_type' => $document->id_type,
                'id_last4' => $idDocumentService->getIdLast4FromDocument($document),
                'created_at' => $document->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Clients/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'birth_year' => $client->birth_year,
                'created_at' => $client->created_at?->toIso8601String(),
            ],
            'id_documents' => $idDocuments,
        ]);
    }

    private function assertCanViewClients(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
            abort(403);
        }
    }
}

