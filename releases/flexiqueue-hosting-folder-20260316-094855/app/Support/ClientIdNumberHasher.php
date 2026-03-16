<?php

namespace App\Support;

use InvalidArgumentException;

final class ClientIdNumberHasher
{
    public static function hash(string $idType, string $idNumber): string
    {
        $normalizedNumber = self::normalizeNumber($idNumber);

        if ($normalizedNumber === '') {
            throw new InvalidArgumentException('ID number is empty after normalization.');
        }

        $upperType = mb_strtoupper($idType, 'UTF-8');
        $preimage = $upperType.'|'.$normalizedNumber;

        return hash('sha256', $preimage);
    }

    private static function normalizeNumber(string $idNumber): string
    {
        $trimmed = trim($idNumber);

        if ($trimmed === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $trimmed = \Normalizer::normalize($trimmed, \Normalizer::FORM_C);
        }

        $upper = mb_strtoupper($trimmed, 'UTF-8');

        // Keep only A–Z and 0–9; strip all other characters (spaces, dashes, punctuation, etc.).
        $normalized = preg_replace('/[^A-Z0-9]/u', '', $upper);

        return $normalized ?? '';
    }
}

