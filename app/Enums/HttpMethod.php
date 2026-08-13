<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The methods worth offering for a health check (STAT-40).
 *
 * GET and HEAD only. A check must be safe to repeat every minute forever, which rules out
 * anything that could change state on the far end.
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';

    /**
     * HEAD responses have no body, so a content assertion can never pass against one
     * (STAT-22). Callers use this to refuse the combination rather than let it fail
     * mysteriously at check time.
     */
    public function returnsBody(): bool
    {
        return $this === self::Get;
    }
}
