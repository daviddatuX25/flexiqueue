<?php

namespace App\Services\Tts;

class TtsAssetIdentity
{
    /**
     * @return array{canonical_key:string,storage_path:string,revision:int,hash:string}
     */
    public function build(
        string $scope,
        int $entityId,
        string $language,
        string $phrase,
        string $voiceId,
        float $rate,
        int $revision = 1,
        ?string $providerKey = null,
        ?string $engineModelKey = null
    ): array {
        $normalizedLanguage = strtolower(trim($language));
        $normalizedPhrase = $this->normalizePhrase($phrase);
        $normalizedVoice = trim($voiceId);
        $normalizedRate = number_format($rate, 3, '.', '');

        $canonicalParts = [
            $scope,
            (string) $entityId,
            $normalizedLanguage,
            $normalizedPhrase,
            $normalizedVoice,
            $normalizedRate,
            'r:'.$revision,
        ];

        // New generations: scope assets by engine + model so different providers never collide.
        if ($providerKey !== null || $engineModelKey !== null) {
            $p = $providerKey ?? 'elevenlabs';
            $m = $engineModelKey ?? '';
            $canonicalParts[] = 'p:'.$p;
            $canonicalParts[] = 'm:'.$m;
        }

        $canonicalInput = implode('|', $canonicalParts);
        $hash = substr(hash('sha256', $canonicalInput), 0, 16);

        $slugBase = $this->slugify($normalizedPhrase);
        $slug = $slugBase !== '' ? substr($slugBase, 0, 40) : 'tts';
        $scopeDir = $scope === 'station' ? 'stations' : 'tokens';
        $storagePath = sprintf(
            'tts/%s/%d/%s/r%d-%s-%s.mp3',
            $scopeDir,
            $entityId,
            $normalizedLanguage,
            $revision,
            $slug,
            $hash
        );

        return [
            'canonical_key' => $canonicalInput,
            'storage_path' => $storagePath,
            'revision' => $revision,
            'hash' => $hash,
        ];
    }

    private function normalizePhrase(string $phrase): string
    {
        $trimmed = trim($phrase);
        if ($trimmed === '') {
            return '';
        }

        return (string) preg_replace('/\s+/u', ' ', $trimmed);
    }

    private function slugify(string $value): string
    {
        $lower = strtolower($value);
        $ascii = (string) preg_replace('/[^a-z0-9]+/', '_', $lower);

        return trim($ascii, '_');
    }
}
