<?php

namespace App\Exceptions;

use RuntimeException;

class IdentityBindingException extends RuntimeException
{
    public function __construct(string $message = 'Client identity binding is required for this program.')
    {
        parent::__construct($message, 422);
    }
}

