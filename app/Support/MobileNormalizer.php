<?php

namespace App\Support;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING.md: normalization rules cannot change
 * after the first row is stored without a full re-hash migration.
 */
final class MobileNormalizer
{
    public static function normalize(string $mobile): string
    {
        $s = trim($mobile);

        // Strip +63 and prepend 0
        if (str_starts_with($s, '+63')) {
            $s = '0' . substr($s, 3);
        }

        // Strip spaces, dashes, parens
        $s = str_replace([' ', '-', '(', ')'], '', $s);

        return $s;
    }
}
