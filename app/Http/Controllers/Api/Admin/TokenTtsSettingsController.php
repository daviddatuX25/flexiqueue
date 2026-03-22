<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TtsPreviewTextRequest;
use App\Http\Requests\TtsPreviewTokenSpokenPartRequest;
use App\Http\Requests\TtsSamplePhraseRequest;
use App\Http\Requests\UpdateTokenTtsSettingsRequest;
use App\Models\Token;
use App\Repositories\TokenTtsSettingRepository;
use App\Services\Tts\AnnouncementBuilder;
use Illuminate\Http\JsonResponse;

class TokenTtsSettingsController extends Controller
{
    public function __construct(
        private TokenTtsSettingRepository $tokenTtsSettingRepository,
        private AnnouncementBuilder $announcementBuilder,
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
                'playback' => $settings->getPlayback(),
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
            $updatePayload['default_languages'] = $this->mergeDefaultLanguages(
                $settings->getDefaultLanguages(),
                $data['languages'],
            );
        }
        if (array_key_exists('playback', $data) && is_array($data['playback'])) {
            $current = $settings->getPlayback();
            $updatePayload['playback'] = array_merge($current, array_intersect_key(
                $data['playback'],
                array_flip(['prefer_generated_audio', 'allow_custom_pronunciation', 'segment_2_enabled'])
            ));
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
                'playback' => $settings->fresh()->getPlayback(),
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
        $settings = $this->tokenTtsSettingRepository->getInstance();
        $token = new Token([
            'physical_id' => $params['alias'],
            'pronounce_as' => $params['pronounce_as'],
        ]);
        $defaults = $settings->getDefaultLanguages()[$params['lang']] ?? [];
        $merged = array_merge($defaults, [
            'pre_phrase' => $params['pre_phrase'],
        ]);
        if ($params['token_phrase'] !== null) {
            $merged['token_phrase'] = $params['token_phrase'];
        }
        $text = $this->announcementBuilder->buildSegment1($token, $settings, $params['lang'], $merged);

        return response()->json(['text' => $text]);
    }

    /**
     * GET /api/admin/tts/preview-text — segment 1 or 2 phrase from AnnouncementBuilder (query params).
     */
    public function previewText(TtsPreviewTextRequest $request): JsonResponse
    {
        $v = $request->validated();
        $segment = (int) $v['segment'];
        $lang = (string) $v['lang'];

        if ($segment === 1) {
            $settings = $this->tokenTtsSettingRepository->getInstance();
            $token = new Token([
                'physical_id' => isset($v['alias']) && is_string($v['alias']) && trim($v['alias']) !== ''
                    ? trim($v['alias'])
                    : 'A1',
                'pronounce_as' => $v['pronounce_as'] ?? 'letters',
            ]);
            $defaults = $settings->getDefaultLanguages()[$lang] ?? [];
            $overrides = [];
            if (array_key_exists('pre_phrase', $v)) {
                $overrides['pre_phrase'] = is_string($v['pre_phrase']) ? $v['pre_phrase'] : '';
            }
            if (array_key_exists('token_phrase', $v) && isset($v['token_phrase']) && is_string($v['token_phrase'])) {
                $overrides['token_phrase'] = $v['token_phrase'];
            }
            if (array_key_exists('token_bridge_tail', $v) && isset($v['token_bridge_tail']) && is_string($v['token_bridge_tail'])) {
                $overrides['token_bridge_tail'] = $v['token_bridge_tail'];
            }
            $merged = array_merge($defaults, $overrides);
            $text = $this->announcementBuilder->buildSegment1($token, $settings, $lang, $merged);
        } else {
            $connector = isset($v['connector_phrase']) && is_string($v['connector_phrase']) && trim($v['connector_phrase']) !== ''
                ? trim($v['connector_phrase'])
                : null;
            $stationPhrase = isset($v['station_phrase']) && is_string($v['station_phrase']) && trim($v['station_phrase']) !== ''
                ? trim($v['station_phrase'])
                : null;
            $stationName = isset($v['station_name']) && is_string($v['station_name']) && trim($v['station_name']) !== ''
                ? trim($v['station_name'])
                : 'Window 1';
            $text = $this->announcementBuilder->buildSegment2FromParts($connector, $stationPhrase, $stationName);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * GET /api/admin/tts/preview-token-spoken-part — token pronunciation body only.
     *
     * Unlike segment 1 preview, this never includes pre_phrase, token_bridge_tail, or closing sentences.
     */
    public function previewTokenSpokenPart(TtsPreviewTokenSpokenPartRequest $request): JsonResponse
    {
        $v = $request->validated();

        $settings = $this->tokenTtsSettingRepository->getInstance();

        $token = new Token([
            'physical_id' => isset($v['alias']) && is_string($v['alias']) && trim($v['alias']) !== '' ? trim($v['alias']) : 'A1',
            'pronounce_as' => (string) $v['pronounce_as'],
        ]);

        $lang = (string) $v['lang'];
        $defaults = $settings->getDefaultLanguages()[$lang] ?? [];

        $merged = $defaults;
        if (array_key_exists('token_phrase', $v)) {
            $merged['token_phrase'] = $v['token_phrase'];
        }

        if (! $settings->getPlayback()['allow_custom_pronunciation']) {
            unset($merged['token_phrase']);
        }

        $text = $this->announcementBuilder->spokenTokenPart($token, $lang, $merged);

        return response()->json(['text' => $text]);
    }

    /**
     * Per-language shallow merge so partial PUTs (e.g. Config without phrase fields, Tokens without voice) do not wipe other editors' keys.
     *
     * @param  array<string, array<string, mixed>>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, array<string, mixed>>
     */
    private function mergeDefaultLanguages(array $existing, array $incoming): array
    {
        $merged = $existing;
        foreach (['en', 'fil', 'ilo'] as $lang) {
            if (array_key_exists($lang, $incoming) && is_array($incoming[$lang])) {
                $merged[$lang] = array_merge($existing[$lang] ?? [], $incoming[$lang]);
            }
        }

        return $merged;
    }
}
