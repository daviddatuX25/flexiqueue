<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Client binding source: how the client was selected when binding a session.
 * Single source of truth for allowed values and which sources require an ID document.
 */
final class ClientBindingSource
{
    public const PHONE_MATCH = 'phone_match';

    public const NEW_CLIENT = 'new_client';

    public const NAME_SEARCH = 'name_search';

    public const MANUAL = 'manual';

    /** @var list<string> */
    private const ALL = [
        self::PHONE_MATCH,
        self::NEW_CLIENT,
        self::NAME_SEARCH,
        self::MANUAL,
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
