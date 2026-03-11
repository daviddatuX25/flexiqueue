<?php

namespace App\Events;

use App\Models\Station;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StationDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Station $station
    ) {}
}
