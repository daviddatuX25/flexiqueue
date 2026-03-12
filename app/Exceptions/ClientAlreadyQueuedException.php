<?php

namespace App\Exceptions;

use App\Models\Session;

class ClientAlreadyQueuedException extends \RuntimeException
{
    public function __construct(
        public Session $activeSession
    ) {
        parent::__construct('Client already has an active session.');
    }
}

