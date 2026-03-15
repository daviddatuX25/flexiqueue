<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        $birthDate = $this->faker->dateTimeBetween('-80 years', '-18 years');

        return [
            'first_name' => $this->faker->firstName(),
            'middle_name' => $this->faker->optional(0.3)->firstName(),
            'last_name' => $this->faker->lastName(),
            'birth_date' => $birthDate->format('Y-m-d'),
            'address_line_1' => $this->faker->optional(0.5)->streetAddress(),
            'address_line_2' => null,
            'city' => $this->faker->optional(0.5)->city(),
            'state' => $this->faker->optional(0.5)->stateAbbr(),
            'postal_code' => $this->faker->optional(0.5)->postcode(),
            'country' => $this->faker->optional(0.5)->countryCode(),
        ];
    }
}

