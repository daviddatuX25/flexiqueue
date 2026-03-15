<?php

namespace App\Services;

use App\Exceptions\DuplicateClientIdDocumentException;
use App\Models\Client;
use App\Models\ClientIdAuditLog;
use App\Models\ClientIdDocument;
use App\Support\ClientIdNumberHasher;
use App\Support\ClientIdTypes;
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

    /**
     * Lookup by id_number only across all id types. Per plan: 0 → not_found, 1 → single match, 2+ → ambiguous.
     *
     * @return array{match_status: 'not_found'|'single'|'ambiguous', client: \App\Models\Client|null, id_document: \App\Models\ClientIdDocument|null, id_types: string[]}
     */
    public function lookupByIdNumberOnly(string $idNumber): array
    {
        $docs = [];
        foreach (ClientIdTypes::all() as $idType) {
            try {
                $hash = ClientIdNumberHasher::hash($idType, $idNumber);
            } catch (\Throwable) {
                continue;
            }
            $doc = ClientIdDocument::with('client')
                ->where('id_type', $idType)
                ->where('id_number_hash', $hash)
                ->first();
            if ($doc) {
                $docs[] = $doc;
            }
        }

        if (count($docs) === 0) {
            return ['match_status' => 'not_found', 'client' => null, 'id_document' => null, 'id_types' => []];
        }
        if (count($docs) === 1) {
            $doc = $docs[0];

            return [
                'match_status' => 'single',
                'client' => $doc->client,
                'id_document' => $doc,
                'id_types' => [$doc->id_type],
            ];
        }

        return [
            'match_status' => 'ambiguous',
            'client' => null,
            'id_document' => null,
            'id_types' => array_values(array_unique(array_map(fn ($d) => $d->id_type, $docs))),
        ];
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

    public function deleteDocument(ClientIdDocument $document): void
    {
        $hasAuditLog = ClientIdAuditLog::query()
            ->where('client_id_document_id', $document->id)
            ->exists();

        if ($hasAuditLog) {
            throw new \InvalidArgumentException('Cannot delete ID document: audit log exists for this document.');
        }

        $document->delete();
    }

    public function reassignDocument(ClientIdDocument $document, Client $target): ClientIdDocument
    {
        if ((int) $document->client_id === (int) $target->id) {
            return $document;
        }

        $document->client_id = $target->id;
        $document->save();

        return $document;
    }

    public function getIdLast4FromDocument(ClientIdDocument $document): string
    {
        $raw = Crypt::decryptString($document->id_number_encrypted);
        $normalized = $this->normalizeNumber($raw);

        return mb_substr($normalized, -4);
    }

    /**
     * Last 4 characters of normalized ID number (same normalization as ID documents).
     * Use when storing last4 at create time (e.g. identity registration) so display is consistent.
     */
    public function getLast4FromRawNumber(string $idNumber): string
    {
        $normalized = $this->normalizeNumber($idNumber);

        return mb_substr($normalized, -4);
    }

    /**
     * Whether a scanned raw ID number matches the stored encrypted value (e.g. for identity registration verify).
     * Uses same normalization as ID documents; does not leak decrypted value.
     */
    public function scannedNumberMatchesStored(string $scannedRaw, string $encryptedStored): bool
    {
        $storedRaw = Crypt::decryptString($encryptedStored);
        $normalizedScanned = $this->normalizeNumber($scannedRaw);
        $normalizedStored = $this->normalizeNumber($storedRaw);

        return $normalizedScanned === $normalizedStored;
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

