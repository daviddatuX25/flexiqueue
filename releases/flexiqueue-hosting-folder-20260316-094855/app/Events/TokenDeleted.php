<?php

namespace App\Events;

use App\Models\Token;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TokenDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Token $token
    ) {}
}
