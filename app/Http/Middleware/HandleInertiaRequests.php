<?php

namespace App\Http\Middleware;

use App\Models\Program;
use App\Services\TtsService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'csrf_token' => csrf_token(),
            'auth' => [
                'user' => $user,
            ],
            'activeProgram' => (function () {
                try {
                    return Program::where('is_active', true)->first();
                } catch (\Throwable) {
                    return null;
                }
            })(),
            'server_tts_configured' => $user?->role === 'admin'
                ? app(TtsService::class)->isEnabled()
                : null,
        ];
    }
}
