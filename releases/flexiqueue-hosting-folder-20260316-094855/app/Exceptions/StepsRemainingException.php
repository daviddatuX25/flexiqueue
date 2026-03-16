<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Per 08-API-SPEC-PHASE1 §3.4: Cannot complete — required steps remaining.
 */
class StepsRemainingException extends RuntimeException
{
    /**
     * @param  array<int, array{step_order: int, station: string, is_required: bool}>  $remainingSteps
     */
    public function __construct(
        string $message,
        public array $remainingSteps
    ) {
        parent::__construct($message, 409);
    }
}
