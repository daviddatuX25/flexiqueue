<?php

namespace App\Services;

use App\Exceptions\IdentityBindingException;
use App\Models\Client;
use App\Models\ClientIdDocument;

class IdentityBindingService
{
    public function __construct(
        private ClientIdDocumentService $clientIdDocumentService,
    ) {
    }

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
        $idDocumentId = isset($clientBinding['id_document_id']) ? (int) $clientBinding['id_document_id'] : null;

        if (! $clientId || ! is_string($source) || $source === '' || ! $idDocumentId) {
            if ($bindingRequired) {
                throw new IdentityBindingException();
            }

            return ['client_id' => null, 'metadata' => null];
        }

        $client = Client::find($clientId);
        $idDocument = ClientIdDocument::find($idDocumentId);

        if (! $client || ! $idDocument || $idDocument->client_id !== $client->id) {
            if ($bindingRequired) {
                throw new IdentityBindingException();
            }

            return ['client_id' => null, 'metadata' => null];
        }

        $idLast4 = $this->clientIdDocumentService->getIdLast4FromDocument($idDocument);

        $metadata = [
            'client_id' => $client->id,
            'binding_mode' => $bindingMode,
            'binding_source' => $bindingSource,
            'id_type' => $idDocument->id_type,
            'id_last4' => $idLast4,
            'matched_existing_client' => true,
            'previous_client_id' => null,
        ];

        return [
            'client_id' => $client->id,
            'metadata' => $metadata,
        ];
    }
}

