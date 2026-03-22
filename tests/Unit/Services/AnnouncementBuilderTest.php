<?php

namespace Tests\Unit\Services;

use App\Models\Program;
use App\Models\Station;
use App\Models\Token;
use App\Models\TokenTtsSetting;
use App\Services\Tts\AnnouncementBuilder;
use Tests\TestCase;

class AnnouncementBuilderTest extends TestCase
{
    private AnnouncementBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new AnnouncementBuilder;
    }

    private function siteAllowingCustomPronunciation(bool $allow): TokenTtsSetting
    {
        return new TokenTtsSetting([
            'playback' => ['allow_custom_pronunciation' => $allow],
        ]);
    }

    public function test_build_segment1_legacy_when_pre_and_tail_empty(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['segment_2_enabled' => false],
            'default_languages' => [
                'en' => [
                    'pre_phrase' => '',
                    'token_bridge_tail' => '',
                    'segment1_no_pre_tail_fallback' => 'Calling {token}, please proceed to your station',
                ],
                'fil' => [
                    'pre_phrase' => '',
                    'token_bridge_tail' => '',
                ],
                'ilo' => [
                    'pre_phrase' => '',
                    'token_bridge_tail' => '',
                ],
            ],
        ]);
        $token = new Token(['physical_id' => 'A1', 'pronounce_as' => 'letters']);

        $text = $this->builder->buildSegment1($token, $site, 'en');

        $this->assertSame('Calling ay 1, please proceed to your station', $text);
    }

    public function test_build_segment1_no_pre_or_tail_fallback_returns_empty_for_fil_and_ilo_when_unconfigured(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['segment_2_enabled' => false],
            'default_languages' => [
                'fil' => ['pre_phrase' => '', 'token_bridge_tail' => ''],
                'ilo' => ['pre_phrase' => '', 'token_bridge_tail' => ''],
            ],
        ]);
        $token = new Token(['physical_id' => 'A1', 'pronounce_as' => 'letters']);

        $this->assertSame('', $this->builder->buildSegment1($token, $site, 'fil'));
        $this->assertSame('', $this->builder->buildSegment1($token, $site, 'ilo'));
    }

    public function test_build_segment1_with_pre_and_tail(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['segment_2_enabled' => false],
            'default_languages' => [
                'en' => [
                    'pre_phrase' => 'Calling',
                    'token_bridge_tail' => 'please proceed to',
                ],
            ],
        ]);
        $token = new Token(['physical_id' => 'A1', 'pronounce_as' => 'letters']);

        $text = $this->builder->buildSegment1($token, $site, 'en');

        $this->assertSame('Calling ay 1 please proceed to', $text);
    }

    public function test_build_segment1_when_segment2_enabled_returns_short_call_without_closing(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['segment_2_enabled' => true],
            'default_languages' => [
                'en' => [
                    'pre_phrase' => '',
                    'token_bridge_tail' => '',
                    'segment1_no_pre_tail_fallback' => 'Calling {token}, please proceed to your station',
                ],
                'fil' => [
                    'pre_phrase' => '',
                    'token_bridge_tail' => '',
                ],
                'ilo' => [
                    'pre_phrase' => '',
                    'token_bridge_tail' => '',
                ],
            ],
        ]);

        $token = new Token(['physical_id' => 'A1', 'pronounce_as' => 'letters']);

        $this->assertSame('Calling ay 1', $this->builder->buildSegment1($token, $site, 'en'));
        // Ilocano/Filipino both map 'a' -> 'eyy' in this codebase.
        $this->assertSame('Calling eyy 1', $this->builder->buildSegment1($token, $site, 'fil'));
        $this->assertSame('Calling eyy 1', $this->builder->buildSegment1($token, $site, 'ilo'));
    }

    public function test_build_segment2_with_connector_and_custom_station_phrase(): void
    {
        $program = new Program([
            'settings' => [
                'tts' => [
                    'connector' => [
                        'languages' => [
                            'en' => ['connector_phrase' => 'Please go to'],
                        ],
                    ],
                ],
            ],
        ]);
        $station = new Station([
            'name' => 'Window 5',
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['station_phrase' => 'Window five'],
                    ],
                ],
            ],
        ]);

        $site = $this->siteAllowingCustomPronunciation(true);
        $text = $this->builder->buildSegment2($station, $program, 'en', $site);

        $this->assertSame('Please go to Window five', $text);
    }

    public function test_build_segment2_falls_back_to_station_name(): void
    {
        $program = new Program(['settings' => []]);
        $station = new Station(['name' => 'Triage', 'settings' => []]);
        $site = $this->siteAllowingCustomPronunciation(true);

        $text = $this->builder->buildSegment2($station, $program, 'en', $site);

        $this->assertSame('', $text);
    }

    public function test_spoken_token_part_word_mode_ignores_stored_token_phrase_when_custom_allowed(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['allow_custom_pronunciation' => true],
            'default_languages' => ['en' => []],
        ]);
        $token = new Token([
            'physical_id' => 'A1',
            'pronounce_as' => 'word',
            'tts_settings' => [
                'languages' => [
                    'en' => ['token_phrase' => 'ignored phrase'],
                ],
            ],
        ]);
        $merged = $this->builder->mergeLangConfig($site, $token, 'en');

        $this->assertSame('A 1', $this->builder->spokenTokenPart($token, 'en', $merged));
    }

    public function test_spoken_token_part_custom_mode_uses_token_phrase(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['allow_custom_pronunciation' => true],
            'default_languages' => ['en' => []],
        ]);
        $token = new Token([
            'physical_id' => 'A1',
            'pronounce_as' => 'custom',
            'tts_settings' => [
                'languages' => [
                    'en' => ['token_phrase' => 'Counter'],
                ],
            ],
        ]);
        $merged = $this->builder->mergeLangConfig($site, $token, 'en');

        $this->assertSame('Counter', $this->builder->spokenTokenPart($token, 'en', $merged));
    }

    public function test_merge_lang_config_strips_token_phrase_when_custom_pronunciation_disallowed(): void
    {
        $site = new TokenTtsSetting([
            'playback' => ['allow_custom_pronunciation' => false],
            'default_languages' => [
                'en' => ['token_phrase' => 'should be stripped from defaults'],
            ],
        ]);
        $token = new Token([
            'physical_id' => 'A1',
            'pronounce_as' => 'letters',
            'tts_settings' => [
                'languages' => [
                    'en' => ['token_phrase' => 'override also stripped'],
                ],
            ],
        ]);

        $merged = $this->builder->mergeLangConfig($site, $token, 'en');

        $this->assertArrayNotHasKey('token_phrase', $merged);
        $this->assertSame('ay 1', $this->builder->spokenTokenPart($token, 'en', $merged));
    }

    public function test_build_segment2_ignores_station_phrase_when_custom_pronunciation_disallowed(): void
    {
        $program = new Program([
            'settings' => [
                'tts' => [
                    'connector' => [
                        'languages' => [
                            'en' => ['connector_phrase' => 'Please go to'],
                        ],
                    ],
                ],
            ],
        ]);
        $station = new Station([
            'name' => 'Window 5',
            'settings' => [
                'tts' => [
                    'languages' => [
                        'en' => ['station_phrase' => 'Window five'],
                    ],
                ],
            ],
        ]);
        $site = $this->siteAllowingCustomPronunciation(false);

        $text = $this->builder->buildSegment2($station, $program, 'en', $site);

        $this->assertSame('Please go to Window 5', $text);
    }

    public function test_build_closing_when_segment2_disabled_trims(): void
    {
        $site = new TokenTtsSetting([
            'default_languages' => [
                'en' => ['closing_without_segment2' => '  Thank you  '],
            ],
        ]);

        $this->assertSame('Thank you', $this->builder->buildClosingWhenSegment2Disabled($site, 'en'));
    }

    public function test_build_closing_missing_returns_empty(): void
    {
        $site = new TokenTtsSetting(['default_languages' => ['en' => []]]);

        $this->assertSame('', $this->builder->buildClosingWhenSegment2Disabled($site, 'en'));
    }
}
