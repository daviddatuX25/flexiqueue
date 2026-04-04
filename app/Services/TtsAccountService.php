<?php

namespace App\Services;

use App\Models\TtsAccount;

/**
 * Per docs/REFACTORING-ISSUE-LIST.md Issue 10: TTS account activation lives here, not in the model.
 */
class TtsAccountService
{
    /**
     * Set the given account as active; deactivate other accounts with the same {@see TtsAccount::$provider}.
     * At most one active row per provider (ADR 001).
     */
    public function setActive(TtsAccount $account): void
    {
        $provider = $account->provider ?? 'elevenlabs';

        TtsAccount::query()
            ->where('id', '!=', $account->id)
            ->where('provider', $provider)
            ->update(['is_active' => false]);

        $account->update(['is_active' => true]);
    }
}
