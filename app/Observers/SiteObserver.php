<?php

namespace App\Observers;

use App\Models\RbacTeam;
use App\Models\Site;

class SiteObserver
{
    public function created(Site $site): void
    {
        RbacTeam::forSite($site);
    }
}
