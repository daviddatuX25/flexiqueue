<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TtsStreamRequest;
use App\Models\Token;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\TtsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/public/tts — stream generated or cached TTS audio. Public, rate-limited.
 * When TTS is disabled or generation fails, returns 503 so client can fall back to browser TTS.
 */
class TtsController extends Controller
{
    public function __construct(
        private readonly TtsService $ttsService,
        private readonly TokenTtsSettingRepository $tokenTtsSettingRepository
    ) {}

    /**
     * Stream TTS audio. Query: text (required), voice (optional), rate (optional).
     * When voice/rate are omitted, defaults come from TokenTtsSetting (or config).
     */
    public function stream(TtsStreamRequest $request): BinaryFileResponse|Response
    {
        $text = $request->validated('text');
        $explicitRate = $request->validated('rate');
        $explicitVoice = $request->validated('voice');

        $settings = $this->tokenTtsSettingRepository->getInstance();
        $voiceId = $explicitVoice !== null && $explicitVoice !== ''
            ? (string) $explicitVoice
            : $settings->getEffectiveVoiceId();
        $rate = $explicitRate !== null
            ? (float) $explicitRate
            : $settings->getEffectiveRate();

        if ($voiceId === null) {
            return response('', Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Cache-Control', 'no-store');
        }

        $path = $this->ttsService->getPath($text, $voiceId, $rate);

        if ($path === null) {
            return response('', Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Cache-Control', 'no-store');
        }

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** GET /api/public/tts/voices — config-driven list for admin dropdown. */
    public function voices(): JsonResponse
    {
        return response()->json(['voices' => $this->ttsService->getVoicesList()]);
    }

    /**
     * GET /api/public/tts/token/{token} — stream pre-generated TTS audio for token. 404 if none.
     */
    public function token(Token $token): BinaryFileResponse|Response
    {
        $path = $token->tts_audio_path;
        if ($path === null || $path === '') {
            return response('', Response::HTTP_NOT_FOUND)->header('Cache-Control', 'no-store');
        }

        // Restrict to tts/tokens/ to prevent path traversal
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, 'tts/tokens/') === false) {
            return response('', Response::HTTP_NOT_FOUND)->header('Cache-Control', 'no-store');
        }

        if (! Storage::exists($path)) {
            return response('', Response::HTTP_NOT_FOUND)->header('Cache-Control', 'no-store');
        }

        $fullPath = Storage::path($path);

        return response()->file($fullPath, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
