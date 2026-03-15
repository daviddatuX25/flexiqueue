<?php

namespace App\Services;

use App\Exceptions\IdentityBindingException;
use App\Models\Client;
use App\Support\ClientBindingSource;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING: resolve client from binding payload.
 * No ID document logic; trust client_id from phone-based validated search.
 */
class IdentityBindingService
{
    /**
     * @param  array<string,mixed>|null  $clientBinding
     * @return array{client_id: int|null, metadata: array<string,mixed>|null}
     *
     * @throws IdentityBindingException when binding is required but missing/invalid.
     */
    public function resolve(?array $clientBinding, bool $bindingAllowed, bool $bindingRequired, string $bindingSource, string $bindingMode): array
    {
        if ($clientBinding === null) {
            if ($bindingRequired) {
                throw new IdentityBindingException();
            }

            return ['client_id' => null, 'metadata' => null];
        }

        $clientId = isset($clientBinding['client_id']) ? (int) $clientBinding['client_id'] : null;
        $source = $clientBinding['source'] ?? null;

        if (! $clientId || ! is_string($source) || $source === '') {
            if ($bindingRequired) {
                throw new IdentityBindingException();
            }

            return ['client_id' => null, 'metadata' => null];
        }

        $client = Client::find($clientId);
        if (! $client) {
            if ($bindingRequired) {
                throw new IdentityBindingException();
            }

            return ['client_id' => null, 'metadata' => null];
        }

        $metadata = [
            'client_id' => $client->id,
            'binding_mode' => $bindingMode,
            'binding_source' => $bindingSource,
            'binding_request_source' => $source,
        ];

        return [
            'client_id' => $client->id,
            'metadata' => $metadata,
        ];
    }
}
