<?php

namespace Database\Seeders;

use Database\Seeders\Central\CentralClientSeeder;
use Database\Seeders\Central\CentralHistorySeeder;
use Database\Seeders\Central\CentralProgramSeeder;
use Database\Seeders\Central\CentralSiteSeeder;
use Database\Seeders\Central\CentralTokenSeeder;
use Database\Seeders\Central\CentralUsersSeeder;
use Illuminate\Database\Seeder;

/**
 * Central server seeder: two orgs, rich multi-day history, all analytics charts populated.
 * Run after migrate:fresh. Per docs/seeder-plan.txt §1.
 *
 * php artisan migrate:fresh --force
 * php artisan db:seed --class=CentralSeeder
 */
class CentralSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DefaultSiteSeeder::class);
        $this->call(PermissionCatalogSeeder::class);
        $this->call(SuperAdminSeeder::class);
        $this->call(CentralSiteSeeder::class);
        $this->call(CentralUsersSeeder::class);
        $this->call(CentralProgramSeeder::class);
        $this->call(CentralTokenSeeder::class);
        $this->call(CentralClientSeeder::class);
        $this->call(CentralHistorySeeder::class);
    }
}
