<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'expected_status_code' => ['required', 'integer', 'min:100', 'max:599'],
            // Optional. Empty means status code and response time only, as before.
            'expected_body' => ['nullable', 'string', 'max:255'],
            'interval_seconds' => ['required', 'integer', 'min:30', 'max:86400'],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:60'],
            'degraded_threshold_ms' => ['required', 'integer', 'min:1', 'max:60000'],
            'is_active' => ['required', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.url' => 'Enter a full address including http:// or https://.',
            'interval_seconds.min' => 'Check no more often than once every 30 seconds.',
            'timeout_seconds.max' => 'A timeout over 60 seconds would outlast the check itself.',
        ];
    }
}
