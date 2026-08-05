<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class ProbeResult
{
    public function __construct(
        public ?int $statusCode,
        public int $responseTimeMs,
        public ?string $error = null,
        /**
         * Present only when the response carried a Retry-After header. Paired with a
         * 503 that is how a deliberate maintenance window announces itself, which is
         * what separates `artisan down` from a host that is genuinely broken.
         */
        public ?string $retryAfter = null,
    ) {}
}
