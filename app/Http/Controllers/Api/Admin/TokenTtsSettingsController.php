<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TtsSamplePhraseRequest;
use App\Http\Requests\UpdateTokenTtsSettingsRequest;
use App\Models\Token;
use App\Repositories\TokenTtsSettingRepository;
use App\Support\TtsPhrase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenTtsSettingsController extends Controller
{
    public function __construct(
        private TokenTtsSettingRepository $tokenTtsSettingRepository
    ) {}

    public function show(): JsonResponse
    {
        $settings = $this->tokenTtsSettingRepository->getInstance();
        $defaults = $settings->default_languages;
        $languages = is_array($defaults) ? $defaults : ['en' => [], 'fil' => [], 'ilo' => []];

        return response()->json([
            'token_tts_settings' => [
                'voice_id' => $settings->voice_id,
                'rate' => $settings->rate,
                'languages' => [
                    'en' => $languages['en'] ?? [],
                    'fil' => $languages['fil'] ?? [],
                    'ilo' => $languages['ilo'] ?? [],
                ],
            ],
        ]);
    }

    public function update(UpdateTokenTtsSettingsRequest $request): JsonResponse
    {
        $settings = $this->tokenTtsSettingRepository->getInstance();
        $data = $request->validated();

        $originalVoiceId = $settings->voice_id;
        $originalRate = $settings->rate;

        $updatePayload = [
            'voice_id' => array_key_exists('voice_id', $data) ? $data['voice_id'] : $settings->voice_id,
            'rate' => array_key_exists('rate', $data) ? $data['rate'] : $settings->rate,
        ];
        if (array_key_exists('languages', $data) && is_array($data['languages'])) {
            $updatePayload['default_languages'] = $data['languages'];
        }
        $settings->update($updatePayload);

        $voiceChanged = array_key_exists('voice_id', $data)
            ? $data['voice_id'] !== $originalVoiceId
            : false;
        $rateChanged = array_key_exists('rate', $data)
            ? (float) $data['rate'] !== (float) $originalRate
            : false;

        $requiresRegeneration = false;
        if ($voiceChanged || $rateChanged) {
            $query = Token::query()->where('tts_pre_generate_enabled', true);
            $user = $request->user();
            if (! $user->isSuperAdmin() && $user->site_id !== null) {
                $query->forSite($user->site_id);
            }
            $requiresRegeneration = $query->exists();
        }

        $defaults = $settings->default_languages;
        $languages = is_array($defaults) ? $defaults : ['en' => [], 'fil' => [], 'ilo' => []];

        return response()->json([
            'token_tts_settings' => [
                'voice_id' => $settings->voice_id,
                'rate' => $settings->rate,
                'languages' => [
                    'en' => $languages['en'] ?? [],
                    'fil' => $languages['fil'] ?? [],
                    'ilo' => $languages['ilo'] ?? [],
                ],
            ],
            'requires_regeneration' => $requiresRegeneration,
        ]);
    }

    /**
     * GET /api/admin/tts/sample-phrase — return the exact phrase that would be spoken for a given language.
     */
    public function samplePhrase(TtsSamplePhraseRequest $request): JsonResponse
    {
        $params = $request->validatedSampleParams();

        $text = TtsPhrase::getSamplePhrase(
            $params['pre_phrase'],
            $params['alias'],
            $params['pronounce_as'],
            $params['lang']
        );

        return response()->json(['text' => $text]);
    }
}

