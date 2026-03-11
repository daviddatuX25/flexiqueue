<?php

namespace App\Services;

use App\Exceptions\DuplicateClientIdDocumentException;
use App\Models\Client;
use App\Models\ClientIdDocument;
use App\Support\ClientIdNumberHasher;
use App\Models\ClientIdAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

class ClientIdDocumentService
{
    /**
     * @return array{client: \App\Models\Client|null, id_document: \App\Models\ClientIdDocument|null}
     */
    public function lookupById(string $idType, string $idNumber): array
    {
        $hash = ClientIdNumberHasher::hash($idType, $idNumber);

        $doc = ClientIdDocument::with('client')
            ->where('id_type', $idType)
            ->where('id_number_hash', $hash)
            ->first();

        if (! $doc) {
            return ['client' => null, 'id_document' => null];
        }

        return ['client' => $doc->client, 'id_document' => $doc];
    }

    public function createForClient(Client $client, string $idType, string $idNumber): ClientIdDocument
    {
        $hash = ClientIdNumberHasher::hash($idType, $idNumber);

        $existing = ClientIdDocument::where('id_type', $idType)
            ->where('id_number_hash', $hash)
            ->first();

        if ($existing) {
            throw new DuplicateClientIdDocumentException();
        }

        return ClientIdDocument::create([
            'client_id' => $client->id,
            'id_type' => $idType,
            'id_number_encrypted' => Crypt::encryptString($idNumber),
            'id_number_hash' => $hash,
        ]);
    }

    public function getIdLast4FromDocument(ClientIdDocument $document): string
    {
        $raw = Crypt::decryptString($document->id_number_encrypted);
        $normalized = $this->normalizeNumber($raw);

        return mb_substr($normalized, -4);
    }

    /**
     * Reveal raw ID number for a single document and write audit log entry.
     *
     * @return array{id_document: array{id:int,client_id:int,id_type:string,id_number:string}}
     */
    public function revealForAdmin(ClientIdDocument $document, User $staffUser, ?string $reason = null): array
    {
        $rawNumber = Crypt::decryptString($document->id_number_encrypted);
        $idLast4 = $this->normalizeNumber($rawNumber);
        $idLast4 = mb_substr($idLast4, -4);

        ClientIdAuditLog::create([
            'client_id' => $document->client_id,
            'client_id_document_id' => $document->id,
            'staff_user_id' => $staffUser->id,
            'action' => 'id_reveal',
            'reason' => $reason,
            'id_type' => $document->id_type,
            'id_last4' => $idLast4,
            'created_at' => now(),
        ]);

        return [
            'id_document' => [
                'id' => $document->id,
                'client_id' => $document->client_id,
                'id_type' => $document->id_type,
                'id_number' => $rawNumber,
            ],
        ];
    }

    private function normalizeNumber(string $idNumber): string
    {
        $trimmed = trim($idNumber);
        if ($trimmed === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $trimmed = \Normalizer::normalize($trimmed, \Normalizer::FORM_C);
        }

        $upper = mb_strtoupper($trimmed, 'UTF-8');

        $normalized = preg_replace('/[^A-Z0-9]/u', '', $upper);

        return $normalized ?? '';
    }
}

