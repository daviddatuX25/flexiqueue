<?php

use App\Models\Token;
use Illuminate\Database\Migrations\Migration;

/**
 * Tokens that used pronounce_as=word with a stored per-lang token_phrase were legacy "custom" wording.
 * Persisted mode is now explicit: custom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Token::query()->where('pronounce_as', 'word')->chunkById(200, function ($tokens): void {
            foreach ($tokens as $token) {
                foreach (['en', 'fil', 'ilo'] as $lang) {
                    $phrase = $token->getTtsConfigFor($lang)['token_phrase'] ?? null;
                    if (is_string($phrase) && trim($phrase) !== '') {
                        $token->update(['pronounce_as' => 'custom']);
                        break;
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Token::query()->where('pronounce_as', 'custom')->update(['pronounce_as' => 'word']);
    }
};
