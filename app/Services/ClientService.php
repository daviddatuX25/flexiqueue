<?php

namespace App\Services;

use App\Models\Client;
use App\Services\MobileCryptoService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientService
{
    public function __construct(
        private MobileCryptoService $mobileCrypto,
    ) {}

    /**
     * Create a client. Per site-scoping-migration-spec §3: set site_id when provided.
     * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING: optional mobile is encrypted/hashed before storing.
     *
     * @param  array{address_line_1?: string, address_line_2?: string, city?: string, state?: string, postal_code?: string, country?: string}  $address
     */
    public function createClient(
        string $firstName,
        string $lastName,
        Carbon|string $birthDate,
        ?int $siteId = null,
        ?string $mobile = null,
        ?string $middleName = null,
        ?array $address = null,
    ): Client {
        $attrs = [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'birth_date' => $birthDate instanceof Carbon ? $birthDate->toDateString() : $birthDate,
        ];
        if ($siteId !== null) {
            $attrs['site_id'] = $siteId;
        }
        if ($mobile !== null && trim($mobile) !== '') {
            $attrs['mobile_encrypted'] = $this->mobileCrypto->encrypt($mobile);
            $attrs['mobile_hash'] = $this->mobileCrypto->hash($mobile);
        }
        if ($address !== null) {
            foreach (['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country'] as $key) {
                if (isset($address[$key]) && $address[$key] !== null && $address[$key] !== '') {
                    $attrs[$key] = $address[$key];
                }
            }
        }

        return Client::create($attrs);
    }

    /**
     * Search by phone. Exact hash match only; never fuzzy.
     * When $siteId is provided, only returns a client for that site.
     */
    public function searchClientsByPhone(string $mobile, ?int $siteId = null): ?Client
    {
        $hash = $this->mobileCrypto->hash($mobile);

        $query = Client::where('mobile_hash', $hash);
        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        return $query->first();
    }

    /**
     * Find a client by mobile hash, excluding the given client id.
     * Used for duplicate-phone detection during mobile number updates.
     */
    public function findByMobileHashExcluding(string $hash, int $excludeId): ?Client
    {
        return Client::where('mobile_hash', $hash)
            ->where('id', '!=', $excludeId)
            ->first();
    }

    /**
     * Find a client by mobile hash scoped to a site.
     * Used for identity registration matching.
     */
    public function findByMobileHashAndSite(string $hash, int $siteId): ?Client
    {
        return Client::where('mobile_hash', $hash)
            ->where('site_id', $siteId)
            ->first();
    }

    /**
     * Retrieve a client by id, throwing ModelNotFoundException if not found.
     */
    public function findOrFail(int $id): Client
    {
        return Client::findOrFail($id);
    }

    /**
     * Search clients by name (and optional birth date). Per site-scoping-migration-spec §3:
     * scope by site_id when provided. Name is tokenized by whitespace; each token must appear
     * in at least one of first_name, middle_name, last_name.
     *
     * @param  array{name: string, birth_date: ?string, per_page: int, page: int, site_id: ?int}  $params
     */
    public function searchClients(array $params): LengthAwarePaginator
    {
        $name = trim($params['name']);
        $tokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', $name))));
        $siteId = $params['site_id'] ?? null;

        $query = Client::query()->orderBy('last_name')->orderBy('first_name');

        $query->forSite($siteId);

        if ($tokens === []) {
            $query->whereRaw('1 = 0');
        } else {
            foreach ($tokens as $token) {
                $query->where(function ($q) use ($token) {
                    $q->where('first_name', 'like', '%' . $token . '%')
                        ->orWhere('middle_name', 'like', '%' . $token . '%')
                        ->orWhere('last_name', 'like', '%' . $token . '%');
                });
            }
        }

        if (isset($params['birth_date']) && $params['birth_date'] !== null && $params['birth_date'] !== '') {
            $query->whereDate('birth_date', $params['birth_date']);
        }

        return $query->paginate(
            perPage: $params['per_page'],
            page: $params['page'],
            columns: [
                'id', 'first_name', 'middle_name', 'last_name', 'birth_date',
                'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
                'mobile_encrypted',
            ]
        );
    }
}

