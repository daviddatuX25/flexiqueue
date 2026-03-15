<?php

namespace App\Services;

use App\Models\Session;
use App\Models\User;
use App\Support\SupervisorAuthResult;
use Illuminate\Support\Facades\RateLimiter;

class SupervisorAuthService
{
    private const PIN_FAIL_THROTTLE_PREFIX = 'pin_auth_fail:';

    private const PIN_FAIL_MAX_ATTEMPTS = 5;

    private const PIN_FAIL_DECAY_MINUTES = 15;

    public function __construct(
        private PinService $pinService,
    ) {
    }

    /**
     * Verify supervisor authorization for a given action.
     *
     * @param  array<string, mixed>  $validated
     * @param  'call'|'force_complete'|'override'  $action
     */
    public function verifyForAction(array $validated, User $actingUser, ?Session $session, string $action): SupervisorAuthResult
    {
        $authType = $this->resolveAuthType($validated);
        if ($authType === null) {
            return SupervisorAuthResult::failure('missing_auth_type');
        }

        if (! in_array($authType, ['preset_pin', 'preset_qr', 'temp_pin', 'temp_qr'], true)) {
            return SupervisorAuthResult::failure('invalid_auth_type');
        }

        $staffUserId = $actingUser->id;

        if ($authType === 'preset_pin') {
            $key = self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId;
            if (RateLimiter::tooManyAttempts($key, self::PIN_FAIL_MAX_ATTEMPTS)) {
                return SupervisorAuthResult::failure('rate_limited');
            }
        }

        $verified = match ($authType) {
            'temp_pin' => $this->pinService->validateTemporaryPin($validated['temp_code'] ?? ''),
            'temp_qr' => $this->pinService->validateTemporaryQr($validated['qr_scan_token'] ?? ''),
            'preset_qr' => $this->pinService->validatePresetQr($validated['qr_scan_token'] ?? ''),
            default => $this->pinService->validate((int) ($validated['supervisor_user_id'] ?? 0), $validated['supervisor_pin'] ?? ''),
        };

        if (! $verified) {
            if (in_array($authType, ['temp_pin', 'temp_qr'], true)) {
                return SupervisorAuthResult::failure('expired_temp');
            }

            if ($authType === 'preset_pin') {
                RateLimiter::hit(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId, self::PIN_FAIL_DECAY_MINUTES * 60);
            }

            return SupervisorAuthResult::failure('invalid_pin');
        }

        if ($authType === 'preset_pin') {
            RateLimiter::clear(self::PIN_FAIL_THROTTLE_PREFIX.$staffUserId);
        }

        if (in_array($authType, ['preset_pin', 'preset_qr'], true)) {
            $authorizer = User::find($verified['user_id']);
            $programId = $session?->program_id;
            if (! $authorizer || ! $this->canAuthorizeForProgram($authorizer, $programId)) {
                return SupervisorAuthResult::failure('unauthorized_program');
            }
        }

        return SupervisorAuthResult::success($verified['user_id']);
    }

    /**
     * Infer auth_type from validated request data, matching legacy behavior.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveAuthType(array $validated): ?string
    {
        $authType = $validated['auth_type'] ?? null;
        if ($authType && in_array($authType, ['preset_pin', 'preset_qr', 'temp_pin', 'temp_qr', 'pin', 'qr'], true)) {
            return $authType === 'pin' ? 'temp_pin' : ($authType === 'qr' ? 'temp_qr' : $authType);
        }
        if (! empty($validated['supervisor_user_id']) && ! empty($validated['supervisor_pin'])) {
            return 'preset_pin';
        }
        if (! empty($validated['temp_code'])) {
            return 'temp_pin';
        }
        if (! empty($validated['qr_scan_token'])) {
            return 'temp_qr';
        }

        return null;
    }

    private function canAuthorizeForProgram(User $authorizer, ?int $programId): bool
    {
        if (! $authorizer) {
            return false;
        }

        if ($authorizer->isAdmin()) {
            return true;
        }

        if ($programId === null) {
            return false;
        }

        return $authorizer->isSupervisorForProgram($programId);
    }
}

