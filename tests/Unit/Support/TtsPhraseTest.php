<?php

namespace Tests\Unit\Support;

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
}
