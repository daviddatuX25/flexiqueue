<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientService
{
    public function createClient(string $name, int $birthYear): Client
    {
        return Client::create([
            'name' => $name,
            'birth_year' => $birthYear,
        ]);
    }

    /**
     * Search clients by name (and optional birth year). Name is tokenized by whitespace:
     * each token must appear in the client's name (in any order). Returns paginated results
     * with has_id_document flag. Per plan: triage search shows 3 nearest matches per page.
     *
     * @param  array{name: string, birth_year: ?int, per_page: int, page: int}  $params
     */
    public function searchClients(array $params): LengthAwarePaginator
    {
        $name = trim($params['name']);
        $tokens = array_values(array_filter(array_map('trim', preg_split('/\s+/', $name))));

        $query = Client::query()->orderBy('name')->withCount('idDocuments');

        if ($tokens === []) {
            $query->whereRaw('1 = 0');
        } else {
            foreach ($tokens as $token) {
                $query->where('name', 'like', '%' . $token . '%');
            }
        }

        if ($params['birth_year'] !== null) {
            $query->where('birth_year', $params['birth_year']);
        }

        return $query->paginate(
            perPage: $params['per_page'],
            page: $params['page'],
            columns: ['id', 'name', 'birth_year']
        );
    }
}

