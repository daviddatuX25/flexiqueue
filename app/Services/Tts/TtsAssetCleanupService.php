<?php

namespace App\Services\Tts;

use App\Models\Station;
use App\Models\Token;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class TtsAssetCleanupService
{
    /**
     * @return array{scanned:int,deleted:int,candidates:int}
     */
    public function cleanupSupersededAssets(int $retentionDays, int $limit, bool $dryRun = true): array
    {
        $threshold = now()->subDays(max(1, $retentionDays));
        $limit = max(1, $limit);
        $candidates = [];

        $scanLanguageConfig = function (array $config) use (&$candidates, $threshold): void {
            $rows = data_get($config, 'asset_meta.replaced_paths', []);
            if (! is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                $path = is_array($row) ? ($row['path'] ?? null) : null;
                $replacedAt = is_array($row) ? ($row['replaced_at'] ?? null) : null;
                if (! is_string($path) || trim($path) === '') {
                    continue;
                }
                if (! is_string($replacedAt)) {
                    continue;
                }
                $parsed = Carbon::parse($replacedAt);
                if ($parsed->greaterThan($threshold)) {
                    continue;
                }
                $candidates[$path] = true;
            }
        };

        Token::query()->select(['id', 'tts_settings'])->chunkById(200, function ($tokens) use ($scanLanguageConfig): void {
            foreach ($tokens as $token) {
                $languages = data_get($token->tts_settings, 'languages', []);
                if (! is_array($languages)) {
                    continue;
                }
                foreach ($languages as $cfg) {
                    if (is_array($cfg)) {
                        $scanLanguageConfig($cfg);
                    }
                }
            }
        });

        Station::query()->select(['id', 'settings'])->chunkById(200, function ($stations) use ($scanLanguageConfig): void {
            foreach ($stations as $station) {
                $languages = data_get($station->settings, 'tts.languages', []);
                if (! is_array($languages)) {
                    continue;
                }
                foreach ($languages as $cfg) {
                    if (is_array($cfg)) {
                        $scanLanguageConfig($cfg);
                    }
                }
            }
        });

        $paths = array_slice(array_keys($candidates), 0, $limit);
        $deleted = 0;

        if (! $dryRun) {
            foreach ($paths as $path) {
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                    $deleted += 1;
                }
            }
        }

        return [
            'scanned' => count($paths),
            'deleted' => $deleted,
            'candidates' => count($candidates),
        ];
    }
}
