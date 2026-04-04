<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per plan: POST /api/public/display-settings — supervisor/admin authorization required; optional display/triage settings.
 * Public (no auth); used by display board and public triage settings modal.
 */
class UpdatePublicDisplaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            // New simplified contract: send either `pin` or `qr_scan_token`.
            // (We still accept legacy auth_type payloads for compatibility.)
            'pin' => [
                'nullable',
                'string',
                'size:6',
                'regex:/^\d{6}$/',
                Rule::requiredIf(fn () => $this->pinOrQrRequired() && ! $this->has('qr_scan_token') && ! $this->has('auth_type')),
            ],
            'qr_scan_token' => [
                'nullable',
                'string',
                'min:1',
                'max:128',
                Rule::requiredIf(fn () => $this->pinOrQrRequired() && ! $this->has('pin') && ! $this->has('auth_type')),
            ],
            // Legacy/compat inputs (ignored by simplified UI, but supported).
            'auth_type' => ['sometimes', 'string', 'in:preset_pin,preset_qr,temp_pin,temp_qr,pin,qr'],
            'supervisor_pin' => ['sometimes', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'temp_code' => ['sometimes', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'display_audio_muted' => ['sometimes', 'boolean'],
            'display_audio_volume' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'enable_display_hid_barcode' => ['sometimes', 'boolean'],
            'enable_public_triage_hid_barcode' => ['sometimes', 'boolean'],
            'kiosk_enable_hid_barcode' => ['sometimes', 'boolean'],
            'enable_display_camera_scanner' => ['sometimes', 'boolean'],
            'enable_public_triage_camera_scanner' => ['sometimes', 'boolean'],
            'kiosk_enable_camera_scanner' => ['sometimes', 'boolean'],
            'kiosk_hid_persistent_when_scan_modal_closed' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Staff/admin signed in on the same browser may omit PIN/QR (verified in PublicDisplaySettingsAuthService).
     */
    private function pinOrQrRequired(): bool
    {
        $user = $this->user();
        $programId = $this->input('program_id');
        if (! $user instanceof User || $programId === null || $programId === '') {
            return true;
        }

        return ! $user->canBypassPublicDisplaySettingsPinForProgram((int) $programId);
    }
}
