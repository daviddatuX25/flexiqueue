<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Support\GoogleOAuthConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per 05-SECURITY-CONTROLS §2.1–2.3: session-based login/logout, role redirect, 5/15 min lockout.
 */
class LoginController extends Controller
{
    use Concerns\RedirectsAuthenticatedUser;

    private const THROTTLE_KEY_PREFIX = 'login:';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_MINUTES = 15;

    public function showLoginForm(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            /** @var User $u */
            $u = Auth::user();

            return $this->redirectAfterLogin($u);
        }

        $payload = [
            'status' => $request->session()->get('status'),
            'error' => $request->session()->get('error'),
            'googleOAuthEnabled' => GoogleOAuthConfig::isConfigured(),
        ];
        if (config('app.demo')) {
            $payload['demo'] = true;
            $payload['demoAccounts'] = config('app.demo_accounts');
        }

        return Inertia::render('Auth/Login', $payload);
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $key = self::THROTTLE_KEY_PREFIX.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return back()->with('error', 'Too many attempts. Please try again in 15 minutes.');
        }

        $credentials = [
            'username' => $request->validated('username'),
            'password' => $request->validated('password'),
        ];

        if (! Auth::attempt($credentials, remember: false)) {
            RateLimiter::hit($key, self::DECAY_MINUTES * 60);

            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return $this->redirectAfterLogin($user);
    }

    /**
     * Log out and set user availability to 'away' so queue/process fallbacks
     * (which count only availability_status = 'available') exclude this user.
     */
    public function logout(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if ($user) {
            $user->update([
                'availability_status' => User::AVAILABILITY_AWAY,
                'availability_updated_at' => now(),
            ]);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
