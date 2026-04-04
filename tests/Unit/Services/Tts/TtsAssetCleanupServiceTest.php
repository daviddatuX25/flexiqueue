<?php

namespace Tests\Unit\Services\Tts;

use App\Models\Token;
use App\Services\Tts\TtsAssetCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TtsAssetCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_superseded_assets_deletes_old_replaced_paths_when_not_dry_run(): void
    {
        Storage::fake('local');
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->tts_settings = [
            'languages' => [
                'en' => [
                    'asset_meta' => [
                        'replaced_paths' => [
                            [
                                'path' => 'tts/tokens/1/en/old.mp3',
                                'replaced_at' => now()->subDays(30)->toIso8601String(),
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $token->save();

        Storage::disk('local')->put('tts/tokens/1/en/old.mp3', 'old');

        $service = app(TtsAssetCleanupService::class);
        $summary = $service->cleanupSupersededAssets(14, 100, false);

        $this->assertSame(1, $summary['deleted']);
        Storage::disk('local')->assertMissing('tts/tokens/1/en/old.mp3');
    }
}
