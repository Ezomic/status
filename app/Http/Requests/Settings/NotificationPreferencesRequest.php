<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class NotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Two states only: every service, or a named subset. Choosing a subset and then
        // naming nothing means the same as switching mail off, so it is rejected rather
        // than stored as an empty set, which the alert query reads as "everything"
        // (STAT-42).
        $narrowed = $this->boolean('wants_incident_mail') && ! $this->boolean('all_services');

        return [
            'wants_incident_mail' => ['required', 'boolean'],
            'all_services' => ['required', 'boolean'],
            'services' => $narrowed
                ? ['required', 'array', 'min:1']
                : ['array'],
            'services.*' => ['integer', 'exists:services,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'services.required' => 'Pick at least one service, or choose every service.',
            'services.min' => 'Pick at least one service, or choose every service.',
        ];
    }
}
