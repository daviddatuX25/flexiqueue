<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicateClientIdDocumentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An ID document with this number already exists.', 409);
    }
}

