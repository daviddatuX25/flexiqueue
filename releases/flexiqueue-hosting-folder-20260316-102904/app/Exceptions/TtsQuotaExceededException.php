<?php

namespace App\Exceptions;

class TtsQuotaExceededException extends \RuntimeException
{
    public static function fromApiResponse(int $status, array $body): self
    {
        $detail = $body['detail'] ?? [];
        $message = is_array($detail) && isset($detail['message'])
            ? (string) $detail['message']
            : "TTS provider quota exceeded (HTTP {$status}).";

        return new self($message);
    }
}
