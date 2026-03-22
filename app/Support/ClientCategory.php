<?php

namespace App\Support;

/**
 * Centralized client category handling. Per Philippine law, PWD, Senior, Pregnant are priority.
 * Normalize at bind time; use exact-match checks only.
 *
 * Staff "Other" free-text is stored as "Other: {detail}" (≤50 chars). It is never a priority lane —
 * same treatment as Regular for alternate/priority queue algorithms (see isPriority).
 */
final class ClientCategory
{
    /** Lowercase keys for exact-match priority checks. */
    private const PRIORITY_KEYS = [
        'pwd',
        'senior',
        'pregnant',
        'pwd / senior / pregnant',
    ];

    /** Maps incoming (lowercase) to canonical display value. */
    private const NORMALIZE_MAP = [
        'pwd / senior / pregnant' => 'PWD / Senior / Pregnant',
        'pwd' => 'PWD',
        'senior' => 'Senior',
        'pregnant' => 'Pregnant',
        'regular' => 'Regular',
        'incomplete documents' => 'Incomplete Documents',
    ];

    /**
     * Normalize incoming client_category to canonical form.
     * Unknown values pass through trimmed; null/empty return null.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        $key = strtolower($trimmed);

        return self::NORMALIZE_MAP[$key] ?? $trimmed;
    }

    /**
     * Whether the category is in the priority lane (exact match).
     * "Other: …" labels from staff triage are never priority.
     */
    public static function isPriority(?string $category): bool
    {
        if ($category === null || $category === '') {
            return false;
        }

        $trim = strtolower(trim($category));
        if (str_starts_with($trim, 'other:')) {
            return false;
        }

        return in_array($trim, self::PRIORITY_KEYS, true);
    }
}
