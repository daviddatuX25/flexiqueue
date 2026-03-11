<?php

namespace Tests\Unit\Listeners;

use App\Events\TokenDeleted;
use App\Listeners\CleanupTokenTtsFiles;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unit tests for CleanupTokenTtsFiles listener. Per docs/REFACTORING-ISSUE-LIST.md Issues 11–12.
 */
class CleanupTokenTtsFilesTest extends TestCase
{
    use RefreshDatabase;

    private function createToken(): Token
    {
        $token = new Token;
        $token->qr_code_hash = hash('sha256', Str::random(32).'A1');
        $token->physical_id = 'A1';
        $token->status = 'available';
        $token->save();

        return $token;
    }

    public function test_handle_deletes_token_tts_directory_and_legacy_audio_path(): void
    {
        Storage::fake('local');

        $token = $this->createToken();
        $token->tts_audio_path = 'tts/tokens/'.$token->id.'.mp3';
        $token->tts_settings = [
            'languages' => [
                'en' => ['audio_path' => 'tts/tokens/'.$token->id.'/en.mp3', 'status' => 'ready'],
            ],
        ];
        $token->save();

        Storage::put('tts/tokens/'.$token->id.'/en.mp3', 'audio');
        Storage::put($token->tts_audio_path, 'audio');

        $listener = new CleanupTokenTtsFiles;
        $listener->handle(new TokenDeleted($token));

        $this->assertFalse(Storage::exists('tts/tokens/'.$token->id));
        $this->assertFalse(Storage::exists($token->tts_audio_path));
        $this->assertFalse(Storage::exists('tts/tokens/'.$token->id.'/en.mp3'));
    }

    public function test_handle_deletes_per_language_audio_from_tts_settings(): void
    {
        Storage::fake('local');

        $token = $this->createToken();
        $token->tts_settings = [
            'languages' => [
                'en' => ['audio_path' => 'tts/tokens/'.$token->id.'/en.mp3', 'status' => 'ready'],
                'fil' => ['audio_path' => 'tts/tokens/'.$token->id.'/fil.mp3', 'status' => 'ready'],
            ],
        ];
        $token->save();

        Storage::put('tts/tokens/'.$token->id.'/en.mp3', 'audio');
        Storage::put('tts/tokens/'.$token->id.'/fil.mp3', 'audio');

        $listener = new CleanupTokenTtsFiles;
        $listener->handle(new TokenDeleted($token));

        $this->assertFalse(Storage::exists('tts/tokens/'.$token->id.'/en.mp3'));
        $this->assertFalse(Storage::exists('tts/tokens/'.$token->id.'/fil.mp3'));
    }

    public function test_handle_does_not_throw_when_paths_missing_or_empty_tts_settings(): void
    {
        Storage::fake('local');

        $token = $this->createToken();

        $listener = new CleanupTokenTtsFiles;
        $listener->handle(new TokenDeleted($token));

        $this->assertFalse(Storage::exists('tts/tokens/'.$token->id));
    }
}
