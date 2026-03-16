<?php

namespace Database\Seeders;

use Database\Seeders\Edge\EdgeClientSeeder;
use Database\Seeders\Edge\EdgeHistorySeeder;
use Database\Seeders\Edge\EdgeProgramSeeder;
use Database\Seeders\Edge\EdgeSiteSeeder;
use Database\Seeders\Edge\EdgeTokenSeeder;
use Database\Seeders\Edge\EdgeUsersSeeder;
use Illuminate\Database\Seeder;

/**
 * Edge (Orange Pi standalone) seeder: one org, AICS only, 10 days history + 3 stale waiting sessions.
 * No DefaultSiteSeeder or SuperAdminSeeder. Run after migrate:fresh. Per docs/seeder-plan.txt §1.
 *
 * php artisan migrate:fresh --force
 * php artisan db:seed --class=EdgeSeeder
 */
class EdgeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(EdgeSiteSeeder::class);
        $this->call(EdgeUsersSeeder::class);
        $this->call(EdgeProgramSeeder::class);
        $this->call(EdgeTokenSeeder::class);
        $this->call(EdgeClientSeeder::class);
        $this->call(EdgeHistorySeeder::class);
    }
}
