<?php

namespace Tests\Unit\Support;

use App\Models\Token;
use App\Support\TtsPhrase;
use PHPUnit\Framework\TestCase;

class TtsPhraseTest extends TestCase
{
    public function test_alias_for_speech_letters_english_a1_uses_ay(): void
    {
        $result = TtsPhrase::aliasForSpeech('A1', 'letters', 'en');
        $this->assertStringContainsString('ay', $result);
        $this->assertStringContainsString('1', $result);
    }

    public function test_alias_for_speech_letters_ilocano_a1_uses_eyy_not_ay(): void
    {
        $result = TtsPhrase::aliasForSpeech('A1', 'letters', 'ilo');
        $this->assertStringContainsString('eyy', $result);
        $this->assertStringNotContainsString('ay', $result);
        $this->assertStringContainsString('1', $result);
    }

    public function test_alias_for_speech_letters_filipino_a_uses_eyy(): void
    {
        $result = TtsPhrase::aliasForSpeech('A', 'letters', 'fil');
        $this->assertSame('eyy', $result);
    }

    public function test_get_sample_phrase_with_pre_phrase_uses_lang_phonetics(): void
    {
        $result = TtsPhrase::getSamplePhrase('Calling', 'A1', 'letters', 'ilo');
        $this->assertStringStartsWith('Calling', $result);
        $this->assertStringContainsString('eyy', $result);
    }

    public function test_get_sample_phrase_without_pre_phrase_returns_full_call_phrase(): void
    {
        $result = TtsPhrase::getSamplePhrase('', 'A1', 'letters', 'en');
        $this->assertStringContainsString('Calling', $result);
        $this->assertStringContainsString('please proceed to your station', $result);
    }

    public function test_token_spoken_part_uses_token_phrase_only_in_custom_mode(): void
    {
        $token = new Token(['physical_id' => 'A1', 'pronounce_as' => 'custom']);
        $part = TtsPhrase::tokenSpokenPartFromMergedConfig($token, 'en', ['token_phrase' => 'Custom ay one']);
        $this->assertSame('Custom ay one', $part);
    }

    public function test_token_spoken_part_ignores_token_phrase_when_word_mode(): void
    {
        $token = new Token(['physical_id' => 'A1', 'pronounce_as' => 'word']);
        $part = TtsPhrase::tokenSpokenPartFromMergedConfig($token, 'en', ['token_phrase' => 'Should ignore']);
        $this->assertSame('A 1', $part);
    }

    public function test_alias_word_mode_splits_letter_runs_and_digits(): void
    {
        $this->assertSame('AAB 3', TtsPhrase::aliasForSpeech('AAB3', 'word', 'en'));
    }

    public function test_get_sample_phrase_respects_token_phrase_override_in_custom_mode(): void
    {
        $result = TtsPhrase::getSamplePhrase('', 'A1', 'custom', 'en', 'Window seven');
        $this->assertStringContainsString('Calling Window seven', $result);
        $this->assertStringNotContainsString('ay', $result);
    }

    public function test_get_sample_phrase_ignores_override_when_letters_mode(): void
    {
        $result = TtsPhrase::getSamplePhrase('', 'A1', 'letters', 'en', 'Window seven');
        $this->assertStringContainsString('ay', $result);
        $this->assertStringNotContainsString('Window seven', $result);
    }
}
