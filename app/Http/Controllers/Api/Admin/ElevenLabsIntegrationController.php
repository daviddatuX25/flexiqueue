<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTtsAccountRequest;
use App\Http\Requests\UpdateTtsAccountRequest;
use App\Models\TtsAccount;
use App\Services\ElevenLabsClient;
use App\Services\TtsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ElevenLabsIntegrationController extends Controller
{
    public function show(TtsService $ttsService): JsonResponse
    {
        $voices = $ttsService->getVoicesList();
        $accounts = TtsAccount::orderBy('is_active', 'desc')->orderBy('label')->get();
        $active = TtsAccount::getActive();

        return response()->json([
            'status' => $ttsService->isEnabled() ? 'connected' : 'not_configured',
            'driver' => config('tts.driver', 'null'),
            'model_id' => $ttsService->getResolvedModelId(),
            'default_voice_id' => $ttsService->getDefaultVoiceId(),
            'voices_count' => is_array($voices) ? count($voices) : 0,
            'accounts' => $accounts->map(fn (TtsAccount $a) => $a->toApiArray()),
            'active_account_id' => $active?->id,
        ]);
    }

    /** List accounts. */
    public function index(): JsonResponse
    {
        $accounts = TtsAccount::orderBy('is_active', 'desc')->orderBy('label')->get();

        return response()->json([
            'accounts' => $accounts->map(fn (TtsAccount $a) => $a->toApiArray()),
        ]);
    }

    /** Create account. */
    public function store(StoreTtsAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $modelId = $data['model_id'] ?? 'eleven_multilingual_v2';

        $account = new TtsAccount([
            'label' => $data['label'],
            'api_key' => $data['api_key'],
            'model_id' => $modelId,
            'is_active' => TtsAccount::count() === 0,
        ]);
        $account->save();

        return response()->json($account->toApiArray(), 201);
    }

    /** Update account. */
    public function update(UpdateTtsAccountRequest $request, TtsAccount $account): JsonResponse
    {
        $data = $request->validated();
        if (isset($data['label'])) {
            $account->label = $data['label'];
        }
        if (array_key_exists('model_id', $data)) {
            $account->model_id = $data['model_id'] ?: 'eleven_multilingual_v2';
        }
        if (isset($data['api_key'])) {
            $account->api_key = $data['api_key'];
        }
        if (isset($data['is_active']) && $data['is_active']) {
            $account->activate();

            return response()->json($account->fresh()->toApiArray());
        }
        $account->save();

        return response()->json($account->toApiArray());
    }

    /** Set account as active. */
    public function activate(TtsAccount $account): JsonResponse
    {
        $account->activate();

        return response()->json($account->fresh()->toApiArray());
    }

    /** Delete account. */
    public function destroy(TtsAccount $account): JsonResponse
    {
        $wasActive = $account->is_active;
        $account->delete();

        if ($wasActive) {
            $next = TtsAccount::first();
            if ($next !== null) {
                $next->activate();
            }
        }

        return response()->json(['deleted' => true]);
    }

    /** Fetch voices from ElevenLabs API (proxy). */
    public function voices(TtsService $ttsService): JsonResponse
    {
        $voices = $ttsService->getVoicesList();

        return response()->json([
            'voices' => $voices,
        ]);
    }

    /**
     * Fetch API usage (subscription quota + optional time-series) for the active ElevenLabs account.
     * Returns 200 with subscription: null when no active account or key invalid; avoids 500.
     */
    public function usage(TtsService $ttsService): JsonResponse
    {
        $apiKey = $ttsService->getResolvedApiKey();
        if ($apiKey === '') {
            return response()->json([
                'subscription' => null,
                'usage_time_series' => null,
                'message' => 'No active ElevenLabs account.',
            ]);
        }

        $cacheKey = 'elevenlabs_usage_'.substr(hash('sha256', $apiKey), 0, 16);
        $cacheTtl = 60 * 10; // 10 minutes

        $data = Cache::remember($cacheKey, $cacheTtl, function () use ($apiKey) {
            $client = new ElevenLabsClient($apiKey);
            $subscription = $client->getSubscription();

            if ($subscription === null) {
                return [
                    'subscription' => null,
                    'usage_time_series' => null,
                    'error' => 'Usage unavailable.',
                ];
            }

            $endMs = (int) (now()->endOfDay()->timestamp * 1000);
            $startMs = (int) (now()->subDays(30)->startOfDay()->timestamp * 1000);
            $usageTimeSeries = $client->getUsageStats($startMs, $endMs, 'day');

            return [
                'subscription' => [
                    'character_count' => $subscription['character_count'],
                    'character_limit' => $subscription['character_limit'],
                    'next_reset_unix' => $subscription['next_character_count_reset_unix'],
                    'tier' => $subscription['tier'],
                ],
                'usage_time_series' => $usageTimeSeries,
                'error' => null,
            ];
        });

        return response()->json([
            'subscription' => $data['subscription'],
            'usage_time_series' => $data['usage_time_series'] ?? null,
            'message' => $data['subscription'] === null ? ($data['error'] ?? 'Usage unavailable.') : null,
        ]);
    }
}
