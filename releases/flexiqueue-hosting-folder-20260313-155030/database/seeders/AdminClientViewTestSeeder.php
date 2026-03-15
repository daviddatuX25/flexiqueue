<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Support\ClientIdNumberHasher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

class AdminClientViewTestSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::updateOrCreate(
            ['name' => 'Admin Reveal Client'],
            [
                'birth_year' => 1990,
            ]
        );

        $raw = '12-3456-7890';
        $idType = 'PhilHealth';

        ClientIdDocument::updateOrCreate(
            [
                'client_id' => $client->id,
                'id_type' => $idType,
            ],
            [
                'id_number_encrypted' => Crypt::encryptString($raw),
                'id_number_hash' => ClientIdNumberHasher::hash($idType, $raw),
            ]
        );
    }
}

