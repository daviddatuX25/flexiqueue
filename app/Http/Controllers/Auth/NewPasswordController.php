<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompletePasswordResetRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §3.3: complete reset; token keyed by recovery_gmail in password_reset_tokens.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response|RedirectResponse
    {
        if (! $request->filled('username')) {
            return redirect()->route('login')->with('error', 'Invalid or expired password reset link.');
        }

        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'username' => $request->string('username')->toString(),
        ]);
    }

    public function store(CompletePasswordResetRequest $request): RedirectResponse
    {
        $status = Password::reset(
            $request->only('username', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', 'Your password has been reset. You can sign in now.');
        }

        $message = match ($status) {
            Password::INVALID_TOKEN => 'This password reset link is invalid or has expired.',
            Password::INVALID_USER => 'We could not find an account for this reset request.',
            default => 'Something went wrong. Please request a new password reset link.',
        };

        return back()->withErrors([
            'username' => [$message],
        ]);
    }
}
