<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleOAuthUserResolver;
use App\Support\GoogleOAuthConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §4.2: /auth/google, /auth/google/callback — no orphan Google users.
 * Authenticated /auth/google/link starts OAuth to attach Google to the current account (PROF-1).
 */
class GoogleAuthController extends Controller
{
    use RedirectsAuthenticatedUser;

    private const SESSION_INTENT = 'oauth_google_intent';

    private const SESSION_LINK_USER_ID = 'oauth_link_user_id';

    public function __construct(
        private GoogleOAuthUserResolver $googleOAuthUserResolver
    ) {}

    public function redirectToGoogle(Request $request): RedirectResponse
    {
        if (! GoogleOAuthConfig::isConfigured()) {
            abort(404);
        }

        $request->session()->forget([self::SESSION_INTENT, self::SESSION_LINK_USER_ID]);

        return Socialite::driver('google')->redirect();
    }

    /**
     * Logged-in user: attach Google if OAuth email matches recovery Gmail on file.
     */
    public function redirectToLinkGoogle(Request $request): RedirectResponse
    {
        if (! GoogleOAuthConfig::isConfigured()) {
            abort(404);
        }

        $request->session()->put(self::SESSION_INTENT, 'link');
        $request->session()->put(self::SESSION_LINK_USER_ID, $request->user()->id);

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        if (! GoogleOAuthConfig::isConfigured()) {
            abort(404);
        }

        try {
            $socialUser = Socialite::driver('google')->user();
        } catch (\Throwable) {
            return $this->redirectAfterOAuthFailure($request);
        }

        $intent = $request->session()->get(self::SESSION_INTENT);
        $linkUserId = $request->session()->get(self::SESSION_LINK_USER_ID);

        if ($intent === 'link' && $linkUserId !== null) {
            $request->session()->forget([self::SESSION_INTENT, self::SESSION_LINK_USER_ID]);

            $user = User::query()->find($linkUserId);
            if ($user === null || ! Auth::check() || (int) Auth::id() !== (int) $linkUserId) {
                return redirect()->route('profile')->with('error', 'Google linking session expired. Sign in again and try linking.');
            }

            $ok = $this->googleOAuthUserResolver->attachGoogleToExistingUser($user, $socialUser);
            if (! $ok) {
                return redirect()->route('profile')->with('error', 'This Google account does not match your recovery Gmail on file, or it is already linked to another user. Contact your administrator if you need to change recovery Gmail.');
            }

            return redirect()->route('profile')->with('success', 'Google account linked. You can use Sign in with Google on the login page.');
        }

        $user = $this->googleOAuthUserResolver->findOrAttachUser($socialUser);
        if ($user === null) {
            return redirect()->route('login')->with('error', 'No FlexiQueue account matches this Google account. Contact your administrator.');
        }

        if (! $user->is_active) {
            return redirect()->route('login')->with('error', 'This account is inactive.');
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return $this->redirectAfterLogin($user);
    }

    private function redirectAfterOAuthFailure(Request $request): RedirectResponse
    {
        if ($request->session()->get(self::SESSION_INTENT) === 'link') {
            $request->session()->forget([self::SESSION_INTENT, self::SESSION_LINK_USER_ID]);

            return redirect()->route('profile')->with('error', 'Google sign-in failed. Try again or contact support.');
        }

        return redirect()->route('login')->with('error', 'Google sign-in failed. Try again or use your username and password.');
    }
}
