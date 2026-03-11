<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Support\ClientIdNumberHasher;
use App\Support\ClientIdTypes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

/**
 * @extends Factory<ClientIdDocument>
 */
class ClientIdDocumentFactory extends Factory
{
    protected $model = ClientIdDocument::class;

    public function definition(): array
    {
        $rawIdNumber = strtoupper($this->faker->bothify('??#######'));
        $idType = ClientIdTypes::PHILHEALTH;

        return [
            'client_id' => Client::factory(),
            'id_type' => $idType,
            'id_number_encrypted' => Crypt::encryptString($rawIdNumber),
            'id_number_hash' => ClientIdNumberHasher::hash($idType, $rawIdNumber),
        ];
    }
}

