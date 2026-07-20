<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class ProbeResult
{
    public function __construct(
        public ?int $statusCode,
        public int $responseTimeMs,
        public ?string $error = null,
    ) {}
}
