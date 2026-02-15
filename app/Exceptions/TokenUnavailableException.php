<?php

namespace App\Exceptions;

class TokenUnavailableException extends \RuntimeException
{
    public function __construct(
        public string $tokenStatus
    ) {
        parent::__construct("Token is marked as {$tokenStatus}.");
    }
}
