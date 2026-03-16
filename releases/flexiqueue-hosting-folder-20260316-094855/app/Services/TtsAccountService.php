<?php

namespace App\Services;

use App\Models\TtsAccount;

/**
 * Per docs/REFACTORING-ISSUE-LIST.md Issue 10: TTS account activation lives here, not in the model.
 */
class TtsAccountService
{
    /**
     * Set the given account as active; deactivate all others.
     * At most one TtsAccount is active at a time.
     */
    public function setActive(TtsAccount $account): void
    {
        TtsAccount::where('id', '!=', $account->id)->update(['is_active' => false]);
        $account->update(['is_active' => true]);
    }
}
