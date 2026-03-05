<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTokenTtsSettingsRequest;
use App\Models\Token;
use App\Models\TokenTtsSetting;
use Illuminate\Http\JsonResponse;

class TokenTtsSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = TokenTtsSetting::instance();

        return response()->json([
            'token_tts_settings' => [
                'voice_id' => $settings->voice_id,
                'rate' => $settings->rate,
            ],
        ]);
    }

    public function update(UpdateTokenTtsSettingsRequest $request): JsonResponse
    {
        $settings = TokenTtsSetting::instance();
        $data = $request->validated();

        $originalVoiceId = $settings->voice_id;
        $originalRate = $settings->rate;

        $settings->update($data);

        $voiceChanged = array_key_exists('voice_id', $data)
            ? $data['voice_id'] !== $originalVoiceId
            : false;
        $rateChanged = array_key_exists('rate', $data)
            ? (float) $data['rate'] !== (float) $originalRate
            : false;

        $requiresRegeneration = false;
        if ($voiceChanged || $rateChanged) {
            $requiresRegeneration = Token::query()
                ->where('tts_pre_generate_enabled', true)
                ->exists();
        }

        return response()->json([
            'token_tts_settings' => [
                'voice_id' => $settings->voice_id,
                'rate' => $settings->rate,
            ],
            'requires_regeneration' => $requiresRegeneration,
        ]);
    }
}

