<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Per plan Step 5: Public device authorization — PIN or QR + program.
 */
class DeviceAuthorizeRequest extends FormRequest
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
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^\d{6}$/'],
            'qr_scan_token' => ['nullable', 'string'],
            'allow_persistent' => ['nullable', 'boolean'],
            'device_key' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Require exactly one of pin or qr_scan_token.
     */
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
