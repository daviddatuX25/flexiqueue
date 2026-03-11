<?php

namespace App\Services;

use App\Models\Client;

class ClientService
{
    public function createClient(string $name, int $birthYear): Client
    {
        return Client::create([
            'name' => $name,
            'birth_year' => $birthYear,
        ]);
    }
}

