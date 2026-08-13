<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MaintenanceWindowRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            // after:starts_at rather than after:now, so a window can be recorded for work
            // already under way rather than only planned ahead.
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'The window has to end after it starts.',
            'service_ids.required' => 'Choose at least one service this window covers.',
        ];
    }
}
