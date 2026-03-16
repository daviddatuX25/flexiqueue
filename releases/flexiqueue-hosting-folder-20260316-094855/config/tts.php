<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TTS Driver
    |--------------------------------------------------------------------------
    | Server-side text-to-speech: 'elevenlabs' (cloud API) or 'null' (disabled).
    | When null, display falls back to browser speechSynthesis.
    */
    'driver' => env('TTS_DRIVER', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Default voice ID
    |--------------------------------------------------------------------------
    | Engine-specific voice ID when none is set in program/station settings.
    | ElevenLabs: use a voice_id from https://api.elevenlabs.io/v1/voices
    */
    'default_voice_id' => env('TTS_DEFAULT_VOICE_ID', '21m00Tcm4TlvDq8ikWAM'),

    /*
    |--------------------------------------------------------------------------
    | Default speech rate (0.5–2.0)
    |--------------------------------------------------------------------------
    | Passed to engine when supported; otherwise ignored. Match display rate.
    */
    'default_rate' => (float) (env('TTS_DEFAULT_RATE', '0.84')),

    /*
    |--------------------------------------------------------------------------
    | Cache path (relative to storage_path())
    |--------------------------------------------------------------------------
    | Generated audio files stored here, keyed by hash(text+voice_id+rate).
    */
    'cache_path' => env('TTS_CACHE_PATH', 'app/tts'),

    /*
    |--------------------------------------------------------------------------
    | Server voices list (config-driven for admin dropdown)
    |--------------------------------------------------------------------------
    | id = engine voice ID; name = label in UI; lang = optional locale hint.
    | ElevenLabs voice IDs: see https://api.elevenlabs.io/v1/voices
    */
    'voices' => [
        ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'lang' => 'en-US'],
        ['id' => 'AZnzlk1XvdvUeBnXmlld', 'name' => 'Domi', 'lang' => 'en-US'],
        ['id' => 'EXAVITQu4vr4xnSDxMaL', 'name' => 'Bella', 'lang' => 'en-US'],
        ['id' => 'ErXwobaYiN019PkySvjV', 'name' => 'Antoni', 'lang' => 'en-US'],
        ['id' => 'MF3mGyEYCl7XYWbV9V6O', 'name' => 'Elli', 'lang' => 'en-US'],
        ['id' => 'TxGEqnHWrfWFTfGW9XjX', 'name' => 'Josh', 'lang' => 'en-US'],
        ['id' => 'VR6AewLTigWG4xSOukaG', 'name' => 'Arnold', 'lang' => 'en-US'],
        ['id' => 'pNInz6obpgDQGcFmaJgB', 'name' => 'Adam', 'lang' => 'en-US'],
        ['id' => 'yoZ06aMxZJJ28mfd3POQ', 'name' => 'Sam', 'lang' => 'en-US'],
        // language next are filipino voices
        ['id' => 'xBXX3d8DJAMukmVFzDUY', 'name' => 'Donnah', 'lang' => 'fil-PH'],
        ['id' => 'ofwR5KwL2nT4XZAub5qx', 'name' => 'Maria - Made For Flexiqueue', 'lang' => 'fil-PH'],
        ['id' => '1ZA0KgPdD0Tns6SR4FVQ', 'name' => 'Juan', 'lang' => 'fil-PH'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync fallback when queue worker unavailable
    |--------------------------------------------------------------------------
    | When true, token/station TTS generation runs synchronously (inline) when
    | the queue worker appears idle instead of returning 503. Use for setups
    | without a running queue worker; large batches may cause slow requests.
    */
    'allow_sync_when_queue_unavailable' => (bool) env('TTS_ALLOW_SYNC_WHEN_QUEUE_UNAVAILABLE', false),

    /*
    |--------------------------------------------------------------------------
    | Max tokens for sync TTS generation
    |--------------------------------------------------------------------------
    | When using sync fallback, cap the number of tokens generated in one
    | request. Above this, API returns 503 and asks to run queue worker.
    */
    'max_sync_tokens' => (int) env('TTS_MAX_SYNC_TOKENS', 20),

    /*
    |--------------------------------------------------------------------------
    | ElevenLabs (when driver = elevenlabs)
    |--------------------------------------------------------------------------
    */
    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY', ''),
        'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
    ],

];
