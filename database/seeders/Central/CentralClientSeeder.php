<?php

namespace Database\Seeders\Central;

use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates 80 clients per site with Filipino names and Ilocano address data. Per docs/seeder-plan.txt §7.
 */
class CentralClientSeeder extends Seeder
{
    private const FIRST_NAMES = [
        'Maria', 'Jose', 'Juan', 'Rosa', 'Ana', 'Pedro', 'Carmen', 'Ramon', 'Nora', 'Dante',
        'Elena', 'Felix', 'Celia', 'Lourdes', 'Ernesto', 'Corazon', 'Rodrigo', 'Lydia', 'Ricardo', 'Teresita',
    ];

    private const MIDDLE_INITIALS = [
        'Santos', 'Dela Cruz', 'Reyes', 'Garcia', 'Flores', 'Bautista', 'Castillo', 'Espiritu', 'Hernandez', 'Ignacio',
    ];

    private const LAST_NAMES = [
        'Santos', 'Dela Cruz', 'Reyes', 'Garcia', 'Flores', 'Bautista', 'Castillo', 'Espiritu', 'Hernandez', 'Ignacio',
        'Jacinto', 'Lopez', 'Manalo', 'Navarro', 'Ocampo', 'Padilla', 'Quiambao', 'Ramos', 'Salvador', 'Torres',
    ];

    private const TAGUDIN_BARANGAYS = [
        'Barangay San Antonio', 'Barangay Poblacion', 'Barangay Bitalag', 'Barangay Damortis', 'Barangay Patpata', 'Barangay Patoc-ao',
    ];

    private const CANDON_BARANGAYS = [
        'Barangay San Nicolas', 'Barangay Poblacion', 'Barangay Caterman', 'Barangay Darapidap', 'Barangay Palacapac',
    ];

    public function run(): void
    {
        foreach (
            [
                'tagudin-mswdo' => ['barangays' => self::TAGUDIN_BARANGAYS, 'city' => 'Tagudin', 'postal_code' => '2727'],
                'candon-mswdo' => ['barangays' => self::CANDON_BARANGAYS, 'city' => 'Candon City', 'postal_code' => '2710'],
            ] as $slug => $loc
        ) {
            $site = Site::where('slug', $slug)->firstOrFail();
            $this->seedClientsForSite($site->id, $loc['barangays'], $loc['city'], $loc['postal_code'], 80);
        }
    }

    private function seedClientsForSite(int $siteId, array $barangays, string $city, string $postalCode, int $count): void
    {
        $seniorCount = 25;
        $middleCount = 30;
        $youngCount = 25;
        $rows = [];
        $now = now();

        for ($i = 0; $i < $seniorCount; $i++) {
            $rows[] = $this->makeClientRow($siteId, $barangays, $city, $postalCode, 1940, 1960, $now);
        }
        for ($i = 0; $i < $middleCount; $i++) {
            $rows[] = $this->makeClientRow($siteId, $barangays, $city, $postalCode, 1961, 1985, $now);
        }
        for ($i = 0; $i < $youngCount; $i++) {
            $rows[] = $this->makeClientRow($siteId, $barangays, $city, $postalCode, 1986, 2000, $now);
        }

        foreach (array_chunk($rows, 40) as $chunk) {
            DB::table('clients')->insert($chunk);
        }
    }

    private function makeClientRow(int $siteId, array $barangays, string $city, string $postalCode, int $yearFrom, int $yearTo, $now): array
    {
        $firstName = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
        $middleName = self::MIDDLE_INITIALS[array_rand(self::MIDDLE_INITIALS)];
        $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
        $year = rand($yearFrom, $yearTo);
        $month = rand(1, 12);
        $day = rand(1, min(28, (int) date('t', mktime(0, 0, 0, $month, 1, $year))));
        $birthDate = Carbon::createFromDate($year, $month, $day)->format('Y-m-d');
        $identityHash = hash('sha256', strtoupper($lastName . '|' . $firstName . '|' . $birthDate));

        return [
            'site_id' => $siteId,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'address_line_1' => $barangays[array_rand($barangays)],
            'address_line_2' => null,
            'city' => $city,
            'state' => 'Ilocos Sur',
            'postal_code' => $postalCode,
            'country' => 'Philippines',
            'identity_hash' => $identityHash,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
