<?php

namespace App\Services\Tts;

class TtsLanguageStatusPresenter
{
    /**
     * @param  array<string,mixed>  $languages
     * @return array<string,mixed>
     */
    public function present(array $languages): array
    {
        $result = [];

        foreach (['en', 'fil', 'ilo'] as $lang) {
            $config = $languages[$lang] ?? [];
            if (! is_array($config)) {
                $config = [];
            }

            $result[$lang] = [
                'voice_id' => $config['voice_id'] ?? null,
                'rate' => $config['rate'] ?? null,
                'status' => $config['status'] ?? null,
                'audio_path' => $config['audio_path'] ?? null,
                'failure_reason' => $config['failure_reason'] ?? null,
                'asset_meta' => is_array($config['asset_meta'] ?? null) ? $config['asset_meta'] : null,
                'pre_phrase' => $config['pre_phrase'] ?? null,
                'token_phrase' => $config['token_phrase'] ?? null,
                'station_phrase' => $config['station_phrase'] ?? null,
            ];
        }

        return $result;
    }
}
