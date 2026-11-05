<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SpikeSignalState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpikeSignalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'source' => ['required', 'string', Rule::in(['snag'])],
            'key' => ['required', 'string', 'max:191'],
            'state' => ['required', Rule::enum(SpikeSignalState::class)],
            'application' => ['required', 'array'],
            'application.id' => ['required', 'integer', 'min:1'],
            'application.name' => ['required', 'string', 'max:255'],
            'route' => ['nullable', 'string', 'max:255'],
            'window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'count' => ['required', 'integer', 'min:0'],
            'baseline_per_window' => ['required', 'numeric', 'min:0'],
            'threshold' => ['required', 'numeric', 'min:0'],
            'detected_at' => ['required', 'date'],
        ];
    }
}
