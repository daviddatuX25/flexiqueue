<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Client binding source: how the client was selected when binding a session.
 * Single source of truth for allowed values and which sources require an ID document.
 */
final class ClientBindingSource
{
    public const EXISTING_ID_DOCUMENT = 'existing_id_document';

    public const NEW_ID_DOCUMENT = 'new_id_document';

    public const NAME_SEARCH = 'name_search';

    public const MANUAL = 'manual';

    /** @var list<string> */
    private const ALL = [
        self::EXISTING_ID_DOCUMENT,
        self::NEW_ID_DOCUMENT,
        self::NAME_SEARCH,
        self::MANUAL,
    ];

    /** @var list<string> Sources that require client_binding.id_document_id. */
    private const REQUIRES_ID_DOCUMENT = [
        self::EXISTING_ID_DOCUMENT,
        self::NEW_ID_DOCUMENT,
    ];

    /**
     * All allowed binding source values (for validation and docs).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::ALL;
    }

    /**
     * Whether this source requires an ID document to be present.
     */
    public static function requiresIdDocument(string $source): bool
    {
        return in_array($source, self::REQUIRES_ID_DOCUMENT, true);
    }

    /**
     * Laravel validation rule for client_binding.source.
     *
     * @return array<int, \Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public static function validationRules(): array
    {
        return [
            'required_with:client_binding',
            'string',
            'max:50',
            Rule::in(self::ALL),
        ];
    }
}
