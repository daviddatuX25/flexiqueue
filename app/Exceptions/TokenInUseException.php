<?php

namespace App\Exceptions;

use App\Models\Session;

class TokenInUseException extends \RuntimeException
{
    public function __construct(
        public Session $activeSession
    ) {
        parent::__construct('Token is already in use.');
    }
}
