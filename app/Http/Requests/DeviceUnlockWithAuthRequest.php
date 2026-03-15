<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unlock device with same PIN or QR as device authorization. One of pin or qr_scan_token required.
 */
class DeviceUnlockWithAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'qr_scan_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $pin = $this->input('pin');
            $qr = $this->input('qr_scan_token');
            $hasPin = is_string($pin) && trim($pin) !== '';
            $hasQr = is_string($qr) && trim($qr) !== '';
            if ($hasPin && $hasQr) {
                $validator->errors()->add('pin', 'Provide either PIN or QR scan token, not both.');
            }
            if (! $hasPin && ! $hasQr) {
                $validator->errors()->add('pin', 'Supervisor PIN or QR scan token is required.');
            }
        });
    }
}
