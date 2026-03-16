<?php

namespace Database\Seeders\Edge;

use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Creates 50 clients for edge (Tagudin barangays). 15 seniors, 20 middle-aged, 15 young adult. Per docs/seeder-plan.txt §13.
 */
class EdgeClientSeeder extends Seeder
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

    public function run(): void
    {
        $site = Site::where('slug', 'tagudin-mswdo-field')->firstOrFail();
        $this->seedClientsForSite($site->id, 50);
    }

    private function seedClientsForSite(int $siteId, int $count): void
    {
        $seniorCount = 15;
        $middleCount = 20;
        $youngCount = 15;
        $rows = [];
        $now = now();

        for ($i = 0; $i < $seniorCount; $i++) {
            $rows[] = $this->makeClientRow($siteId, $now, 1940, 1960);
        }
        for ($i = 0; $i < $middleCount; $i++) {
            $rows[] = $this->makeClientRow($siteId, $now, 1961, 1985);
        }
        for ($i = 0; $i < $youngCount; $i++) {
            $rows[] = $this->makeClientRow($siteId, $now, 1986, 2000);
        }

        foreach (array_chunk($rows, 25) as $chunk) {
            DB::table('clients')->insert($chunk);
        }
    }

    private function makeClientRow(int $siteId, $now, int $yearFrom, int $yearTo): array
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
            'address_line_1' => self::TAGUDIN_BARANGAYS[array_rand(self::TAGUDIN_BARANGAYS)],
            'address_line_2' => null,
            'city' => 'Tagudin',
            'state' => 'Ilocos Sur',
            'postal_code' => '2727',
            'country' => 'Philippines',
            'identity_hash' => $identityHash,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
