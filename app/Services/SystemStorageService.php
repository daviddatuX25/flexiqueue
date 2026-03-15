<?php

namespace App\Services;

use App\Models\Station;
use App\Models\Token;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class SystemStorageService
{
    /**
     * Return a snapshot of disk and key app storage areas.
     * Per site-scoping-migration-spec §2: when $siteId provided, TTS referenced/orphaned are scoped to that site's tokens.
     *
     * @param  int|null  $siteId  Scope TTS token references to this site; null = all tokens.
     */
    public function getStorageSummary(?int $siteId = null): array
    {
        $storageRoot = storage_path();

        $total = @disk_total_space($storageRoot) ?: 0;
        $free = @disk_free_space($storageRoot) ?: 0;
        $used = max(0, $total - $free);
        $usedPercent = $total > 0 ? round(($used / $total) * 100, 1) : 0.0;

        $ttsDirs = $this->getTtsStorageDirs();
        $ttsTotal = $this->sumDirectories($ttsDirs);
        $referenced = $this->getReferencedTtsPaths($siteId);
        $orphaned = $this->computeTtsOrphaned($ttsDirs, $referenced);

        $categories = [
            'tts_audio' => array_merge([
                'bytes' => $ttsTotal['bytes'] ?? 0,
                'file_count' => $ttsTotal['file_count'] ?? 0,
            ], $orphaned),
            'profile_avatars' => $this->directorySize(storage_path('app/public/avatars')),
            'print_images' => $this->directorySize(storage_path('app/public/print-settings')),
            'logs' => $this->directorySize(storage_path('logs')),
            'database' => $this->sumDirectories([
                database_path(),
                database_path('database.sqlite'),
            ]),
        ];

        // Normalise to ensure consistent keys.
        $categories = collect($categories)
            ->map(function (array $value, string $key): array {
                $base = [
                    'bytes' => Arr::get($value, 'bytes', 0),
                    'file_count' => Arr::get($value, 'file_count', 0),
                ];
                if ($key === 'tts_audio') {
                    $base['orphaned_bytes'] = Arr::get($value, 'orphaned_bytes', 0);
                    $base['orphaned_file_count'] = Arr::get($value, 'orphaned_file_count', 0);
                }

                return $base;
            })
            ->all();

        return [
            'disk' => [
                'total_bytes' => $total,
                'free_bytes' => $free,
                'used_bytes' => $used,
                'used_percent' => $usedPercent,
            ],
            'categories' => $categories,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * All TTS paths currently referenced by Token or Station (relative to default disk).
     * Per site-scoping-migration-spec §2: when $siteId provided, only tokens in that site.
     *
     * @param  int|null  $siteId  Scope to this site's tokens; null = all tokens.
     * @return array<string>
     */
    private function getReferencedTtsPaths(?int $siteId = null): array
    {
        $paths = [];

        $tokenQuery = Token::query();
        if ($siteId !== null) {
            $tokenQuery->forSite($siteId);
        }
        foreach ($tokenQuery->get() as $token) {
            if (! empty($token->tts_audio_path)) {
                $paths[] = ltrim(str_replace('\\', '/', (string) $token->tts_audio_path), '/');
            }
            $settings = $token->tts_settings ?? [];
            if (isset($settings['languages']) && is_array($settings['languages'])) {
                foreach ($settings['languages'] as $config) {
                    if (! empty($config['audio_path'])) {
                        $paths[] = ltrim(str_replace('\\', '/', (string) $config['audio_path']), '/');
                    }
                }
            }
        }

        foreach (Station::query()->get() as $station) {
            $settings = $station->settings ?? [];
            if (isset($settings['tts']['languages']) && is_array($settings['tts']['languages'])) {
                foreach ($settings['tts']['languages'] as $config) {
                    if (! empty($config['audio_path'])) {
                        $paths[] = ltrim(str_replace('\\', '/', (string) $config['audio_path']), '/');
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * All known TTS storage directories (current + legacy). Used for summary and clear.
     *
     * @return array<int, string>
     */
    private function getTtsStorageDirs(): array
    {
        return [
            storage_path((string) config('tts.cache_path', 'app/tts')),
            storage_path('app/private/tts'),
            storage_path('app/private/app/tts'),  // legacy; may have been written by old code
        ];
    }

    /**
     * Compute orphaned bytes and file count for TTS dirs: files not in referenced set.
     * Config cache dir (app/tts) has no path-based references; private/tts uses paths relative to storage/app/private.
     *
     * @param  array<int, string>  $dirs
     * @param  array<string>  $referencedPaths  Paths as stored in DB (relative to disk root, e.g. tts/tokens/1.mp3)
     * @return array{orphaned_bytes: int, orphaned_file_count: int}
     */
    private function computeTtsOrphaned(array $dirs, array $referencedPaths): array
    {
        $referencedSet = array_fill_keys($referencedPaths, true);
        $appPrivateRoot = rtrim(str_replace('\\', '/', storage_path('app/private')), '/');
        $cachePath = rtrim(str_replace('\\', '/', (string) storage_path((string) config('tts.cache_path', 'app/tts'))), '/');

        $orphanedBytes = 0;
        $orphanedCount = 0;

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $dirNormalized = rtrim(str_replace('\\', '/', $dir), '/');
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $fullPath = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
                // Path relative to storage/app/private (so it matches DB: e.g. tts/tokens/1.mp3)
                if (str_starts_with($fullPath, $appPrivateRoot.'/')) {
                    $relativePath = substr($fullPath, strlen($appPrivateRoot) + 1);
                } elseif (str_starts_with($fullPath, $cachePath.'/')) {
                    // Config cache: not referenced by path in DB; all files are orphaned
                    $relativePath = null;
                } else {
                    continue;
                }

                $size = $file->getSize();
                if ($relativePath === null) {
                    $orphanedBytes += $size;
                    $orphanedCount++;
                } elseif (! isset($referencedSet[$relativePath])) {
                    $orphanedBytes += $size;
                    $orphanedCount++;
                }
            }
        }

        return [
            'orphaned_bytes' => $orphanedBytes,
            'orphaned_file_count' => $orphanedCount,
        ];
    }

    /**
     * Delete only orphan TTS files (not referenced by any Token or Station). Does not modify DB.
     * Per site-scoping-migration-spec §2: when $siteId provided, "referenced" = only that site's tokens.
     *
     * @param  int|null  $siteId  Scope referenced paths to this site's tokens; null = all.
     * @return array{bytes: int, file_count: int}
     */
    public function clearOrphanedTtsOnly(?int $siteId = null): array
    {
        $dirs = $this->getTtsStorageDirs();
        $referenced = $this->getReferencedTtsPaths($siteId);
        $referencedSet = array_fill_keys($referenced, true);
        $appPrivateRoot = rtrim(str_replace('\\', '/', storage_path('app/private')), '/');
        $cachePath = rtrim(str_replace('\\', '/', (string) storage_path((string) config('tts.cache_path', 'app/tts'))), '/');
        $storageRoot = realpath(storage_path()) ?: storage_path();

        $totalBytes = 0;
        $totalFiles = 0;
        $toDelete = [];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $resolved = realpath($dir);
            if ($resolved === false || ! str_starts_with($resolved, $storageRoot)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
                )
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $fullPath = str_replace('\\', '/', $file->getRealPath() ?: $file->getPathname());
                $isOrphan = false;
                if (str_starts_with($fullPath, $appPrivateRoot.'/')) {
                    $relativePath = substr($fullPath, strlen($appPrivateRoot) + 1);
                    $isOrphan = ! isset($referencedSet[$relativePath]);
                } elseif (str_starts_with($fullPath, $cachePath.'/')) {
                    $isOrphan = true;
                }

                if ($isOrphan) {
                    $toDelete[] = [$file->getPathname(), $file->getSize()];
                }
            }
        }

        foreach ($toDelete as [$path, $size]) {
            if (@unlink($path)) {
                $totalBytes += $size;
                $totalFiles++;
            }
        }

        return [
            'bytes' => $totalBytes,
            'file_count' => $totalFiles,
        ];
    }

    /**
     * Calculate total size and file count for a directory.
     */
    private function directorySize(string $path): array
    {
        $bytes = 0;
        $files = 0;

        if (! is_dir($path)) {
            if (is_file($path)) {
                $size = @filesize($path) ?: 0;

                return ['bytes' => $size, 'file_count' => 1];
            }

            return ['bytes' => 0, 'file_count' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
            )
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $files++;
            $bytes += $file->getSize();
        }

        return ['bytes' => $bytes, 'file_count' => $files];
    }

    /**
     * Sum multiple directories into a single category.
     *
     * @param  array<int, string>  $paths
     */
    private function sumDirectories(array $paths): array
    {
        $totalBytes = 0;
        $totalFiles = 0;

        foreach ($paths as $path) {
            $result = $this->directorySize($path);
            $totalBytes += $result['bytes'] ?? 0;
            $totalFiles += $result['file_count'] ?? 0;
        }

        return ['bytes' => $totalBytes, 'file_count' => $totalFiles];
    }

    /**
     * Clear a storage category (delete files, then null DB references for tts_audio).
     * Per site-scoping-migration-spec §2: when $siteId provided for tts_audio, only null refs and delete files for that site's tokens.
     *
     * @param  int|null  $siteId  For tts_audio: scope to this site's tokens; null = all tokens (legacy/super_admin).
     * @return array{bytes: int, file_count: int}
     */
    public function clearCategory(string $category, ?int $siteId = null): array
    {
        if ($category !== 'tts_audio') {
            return ['bytes' => 0, 'file_count' => 0];
        }

        if ($siteId === null) {
            // Legacy/super_admin: full clear (clean dirs, null all tokens and stations).
            $dirs = $this->getTtsStorageDirs();
            $storageRoot = realpath(storage_path()) ?: storage_path();
            $totalBytes = 0;
            $totalFiles = 0;
            foreach ($dirs as $dir) {
                $resolved = is_dir($dir) ? realpath($dir) : false;
                if ($resolved === false || ! str_starts_with($resolved, $storageRoot)) {
                    continue;
                }
                $size = $this->directorySize($resolved);
                $totalBytes += $size['bytes'] ?? 0;
                $totalFiles += $size['file_count'] ?? 0;
                File::cleanDirectory($dir);
            }
            Token::query()->each(function (Token $token): void {
                $token->tts_audio_path = null;
                $token->tts_status = null;
                $settings = $token->tts_settings ?? [];
                if (isset($settings['languages']) && is_array($settings['languages'])) {
                    foreach (array_keys($settings['languages']) as $lang) {
                        if (is_array($settings['languages'][$lang])) {
                            $settings['languages'][$lang]['audio_path'] = null;
                            $settings['languages'][$lang]['status'] = null;
                        }
                    }
                    $token->tts_settings = $settings;
                }
                $token->save();
            });
            Station::query()->each(function (Station $station): void {
                $settings = $station->settings ?? [];
                if (isset($settings['tts']['languages']) && is_array($settings['tts']['languages'])) {
                    foreach (array_keys($settings['tts']['languages']) as $lang) {
                        if (is_array($settings['tts']['languages'][$lang])) {
                            $settings['tts']['languages'][$lang]['audio_path'] = null;
                            $settings['tts']['languages'][$lang]['status'] = null;
                        }
                    }
                    $station->settings = $settings;
                    $station->save();
                }
            });

            return ['bytes' => $totalBytes, 'file_count' => $totalFiles];
        }

        // Site-scoped: only delete files for this site's tokens and null their refs.
        $tokenQuery = Token::query()->forSite($siteId);
        $tokens = $tokenQuery->get();
        $pathsToDelete = [];
        foreach ($tokens as $token) {
            if (! empty($token->tts_audio_path)) {
                $pathsToDelete[] = ltrim(str_replace('\\', '/', (string) $token->tts_audio_path), '/');
            }
            $settings = $token->tts_settings ?? [];
            if (isset($settings['languages']) && is_array($settings['languages'])) {
                foreach ($settings['languages'] as $config) {
                    if (! empty($config['audio_path'])) {
                        $pathsToDelete[] = ltrim(str_replace('\\', '/', (string) $config['audio_path']), '/');
                    }
                }
            }
        }
        $appPrivateRoot = rtrim(str_replace('\\', '/', storage_path('app/private')), '/');
        $totalBytes = 0;
        $totalFiles = 0;
        foreach ($pathsToDelete as $relPath) {
            $fullPath = $appPrivateRoot.'/'.$relPath;
            if (is_file($fullPath)) {
                $size = @filesize($fullPath) ?: 0;
                if (@unlink($fullPath)) {
                    $totalBytes += $size;
                    $totalFiles++;
                }
            }
        }
        foreach ($tokens as $token) {
            $token->tts_audio_path = null;
            $token->tts_status = null;
            $settings = $token->tts_settings ?? [];
            if (isset($settings['languages']) && is_array($settings['languages'])) {
                foreach (array_keys($settings['languages']) as $lang) {
                    if (is_array($settings['languages'][$lang])) {
                        $settings['languages'][$lang]['audio_path'] = null;
                        $settings['languages'][$lang]['status'] = null;
                    }
                }
                $token->tts_settings = $settings;
            }
            $token->save();
        }

        return ['bytes' => $totalBytes, 'file_count' => $totalFiles];
    }
}

