<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Program;
use App\Services\MobileCryptoService;
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
    /**
     * Per site-scoping-migration-spec §3: scope by user.site_id; 403 if null.
     * Per SUPER-ADMIN-VS-ADMIN-SPEC: super_admin has no access to clients (assertCanViewClients).
     */
    public function index(Request $request): Response
    {
        $this->assertCanViewClients($request);

        /** @var User $user */
        $user = $request->user();
        $siteId = $user->site_id;
        if ($siteId === null) {
            abort(403, 'Site admin must have an assigned site to view clients.');
        }

        $search = (string) $request->query('search', '');

        $query = Client::query()
            ->forSite($siteId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderByDesc('created_at');

        if ($search !== '') {
            $term = '%'.$search.'%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('middle_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term);
            });
        }

        $mobileCrypto = app(MobileCryptoService::class);
        $clients = $query
            ->get()
            ->map(function (Client $client) use ($mobileCrypto) {
                $mobileMasked = $client->mobile_encrypted
                    ? $mobileCrypto->mask($mobileCrypto->decrypt($client->mobile_encrypted))
                    : null;

                return [
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
                ];
            });

        $programs = Program::query()
            ->forSite($siteId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'search' => $search !== '' ? $search : null,
            'programs' => $programs,
        ]);
    }

    public function show(Client $client, MobileCryptoService $mobileCrypto): Response
    {
        $this->assertCanViewClients(request());

        /** @var User $user */
        $user = request()->user();
        if ($user->site_id === null || $client->site_id !== $user->site_id) {
            abort(404);
        }

        $mobileMasked = $client->mobile_encrypted
            ? $mobileCrypto->mask($mobileCrypto->decrypt($client->mobile_encrypted))
            : null;

        return Inertia::render('Admin/Clients/Show', [
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

    private function assertCanViewClients(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isAdmin() && ! $user->isSupervisorForAnyProgram()) {
            abort(403);
        }
    }
}

