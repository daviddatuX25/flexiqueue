<?php

namespace App\Jobs;

use App\Models\Token;
use App\Services\TtsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate and store TTS audio for tokens. Dispatched after batch create when generate_tts is true.
 */
class GenerateTokenTtsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int>  $tokenIds
     */
    public function __construct(
        public array $tokenIds
    ) {}

    public function handle(TtsService $ttsService): void
    {
        if (! $ttsService->isEnabled()) {
            return;
        }

        foreach ($this->tokenIds as $tokenId) {
            $token = Token::find($tokenId);
            if (! $token) {
                continue;
            }

            try {
                $ttsService->storeTokenTts($token);
            } catch (\Throwable $e) {
                Log::warning('TTS generation failed for token {id}: {message}', [
                    'id' => $token->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
