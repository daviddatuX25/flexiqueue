<?php

namespace App\Support;

class SupervisorAuthResult
{
    public function __construct(
        public bool $ok,
        public ?int $authorizerUserId = null,
        public ?string $failureCode = null,
    ) {
    }

    public static function success(int $authorizerUserId): self
    {
        return new self(true, $authorizerUserId, null);
    }

    public static function failure(string $failureCode): self
    {
        return new self(false, null, $failureCode);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function code(): ?string
    {
        return $this->failureCode;
    }
}

