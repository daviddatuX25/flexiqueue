<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdateOverridePinRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Services\TokenPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Per PIN-QR-AUTHORIZATION-SYSTEM AUTH-2: Profile endpoints for preset PIN/QR.
 * Authenticated user only; admin cannot view raw PIN/QR.
 */
class ProfileController extends Controller
{
    public function __construct(
        private TokenPrintService $tokenPrintService
    ) {}

    /**
     * PUT /api/profile/override-pin — Set or update preset PIN. Requires current password.
     */
    public function updateOverridePin(UpdateOverridePinRequest $request): JsonResponse
    {
        $request->user()->update([
            'override_pin' => Hash::make($request->validated('new_pin')),
        ]);

        return response()->json(['message' => 'Override PIN updated.']);
    }

    /**
     * GET /api/profile/override-qr — Whether user has a preset QR set. Never returns the QR/image.
     */
    public function showOverrideQr(Request $request): JsonResponse
    {
        $has = (bool) $request->user()->override_qr_token;

        return response()->json(['has_preset_qr' => $has]);
    }

    /**
     * POST /api/profile/override-qr/regenerate — Generate new preset QR token; return QR image once.
     * Previous preset QR is invalidated.
     */
    public function regenerateOverrideQr(Request $request): JsonResponse
    {
        $token = Str::random(64);
        $request->user()->update([
            'override_qr_token' => Hash::make($token),
        ]);

        $qrDataUri = $this->tokenPrintService->generateQrDataUri($token);

        return response()->json([
            'qr_data_uri' => $qrDataUri,
            'message' => 'Preset QR regenerated. Save or print it; it will not be shown again.',
        ]);
    }

    /**
     * PUT /api/profile/password — Update account password. Requires current password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $request->user()->update([
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * POST /api/profile/avatar — Upload profile photo. Replaces existing avatar.
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('avatar');

        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = $user->id.'_'.Str::random(8).'.'.$ext;

        Storage::disk('public')->makeDirectory('avatars');
        $path = $file->storeAs('avatars', $filename, 'public');

        $oldPath = $user->avatar_path;
        $user->update(['avatar_path' => $filename]);

        if ($oldPath) {
            Storage::disk('public')->delete('avatars/'.$oldPath);
        }

        // Per ISSUES-ELABORATION §9: ensure stored file is readable; return URL so frontend can show immediately
        if (! Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Avatar could not be stored.'], 500);
        }

        // Return URL from refreshed user so frontend and next Inertia load see the same value
        $user->refresh();
        $avatarUrl = $user->avatar_url;

        return response()->json([
            'avatar_url' => $avatarUrl,
            'message' => 'Avatar updated.',
        ]);
    }
}
