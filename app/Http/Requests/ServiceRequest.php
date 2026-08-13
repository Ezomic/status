<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\HttpMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:255'],
            'http_method' => ['required', Rule::enum(HttpMethod::class)],
            'expected_status_code' => ['required', 'integer', 'min:100', 'max:599'],
            // Optional. Empty means status code and response time only, as before.
            // Refused rather than silently ignored when paired with HEAD: a HEAD response
            // has no body, so the assertion could never pass and the service would sit
            // permanently down for a reason nobody could see (STAT-40 vs STAT-22).
            'expected_body' => [
                'nullable',
                'string',
                'max:255',
                Rule::prohibitedIf(fn (): bool => $this->input('http_method') === HttpMethod::Head->value),
            ],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string', 'max:1000'],
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
            'expected_body.prohibited' => 'A HEAD request has no body to match. Use GET, or clear the expected content.',
        ];
    }
}
