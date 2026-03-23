<?php

namespace App\Support;

/**
 * Single place to know if Google OAuth is enabled (Socialite + login / profile UI).
 *
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md H2: leave GOOGLE_CLIENT_ID empty to disable.
 */
final class GoogleOAuthConfig
{
    public static function isConfigured(): bool
    {
        $id = config('services.google.client_id');

        return is_string($id) && $id !== '';
    }
}
