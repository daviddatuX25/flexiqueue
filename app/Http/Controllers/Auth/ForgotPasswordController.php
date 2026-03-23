<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per HYBRID_AUTH_ADMIN_FIRST_PRD.md §3.3 PWD-1: forgot by username; mail to recovery_gmail.
 */
class ForgotPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
            'error' => $request->session()->get('error'),
        ]);
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $username = $request->validated('username');
        $user = User::query()->where('username', $username)->first();

        if ($user === null || $user->recovery_gmail === null || $user->recovery_gmail === '') {
            return back()->with('status', $this->genericResponseMessage());
        }

        $status = Password::broker()->sendResetLink(['username' => $username]);

        if ($status === Password::RESET_THROTTLED) {
            return back()->with('error', 'Please wait before requesting another reset link.');
        }

        return back()->with('status', $this->genericResponseMessage());
    }

    private function genericResponseMessage(): string
    {
        return 'If an account exists with that username and a recovery email on file, we sent a password reset link.';
    }
}
