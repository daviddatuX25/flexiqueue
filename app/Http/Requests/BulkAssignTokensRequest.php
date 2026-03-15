<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Per central-edge C.3.2: POST /api/admin/programs/{program}/tokens/bulk
 * Body: { "pattern": "A*" } — physical_id LIKE match. Safe LIKE: single trailing * converted to %.
 */
class BulkAssignTokensRequest extends FormRequest
{
    public const PATTERN_MIN_LENGTH = 1;

    public const PATTERN_MAX_LENGTH = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pattern' => [
                'required',
                'string',
                'min:'.self::PATTERN_MIN_LENGTH,
                'max:'.self::PATTERN_MAX_LENGTH,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->has('pattern')) {
                return;
            }

            $raw = (string) $this->input('pattern', '');
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return;
            }

            // If, after trimming, the pattern consists only of wildcard characters,
            // reject it to avoid "match everything" bulk assignments.
            $nonWildcard = preg_replace('/[\*\%\_]+/', '', $trimmed);
            if ($nonWildcard === '') {
                $v->errors()->add(
                    'pattern',
                    'The pattern must include at least one non-wildcard character.'
                );
            }
        });
    }

    /**
     * SQL LIKE pattern: escape % and _, allow single trailing * as wildcard.
     */
    public function getLikePattern(): string
    {
        $pattern = $this->validated('pattern');
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);
            $prefix = str_replace(['%', '_'], ['\\%', '\\_'], $prefix);

            return $prefix.'%';
        }

        return str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
    }
}
