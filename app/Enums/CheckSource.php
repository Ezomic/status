<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a check was taken from (STAT-45).
 *
 * STAT-41 measured Tracker at 81ms from outside the droplet against a 967ms internal
 * median over the same period. Those are both real numbers about different things, so
 * they cannot share a column: averaged together they describe neither.
 */
enum CheckSource: string
{
    /** Taken by monitor:run on the droplet, which is every check recorded so far. */
    case Internal = 'internal';

    /** Pushed in by something outside the droplet (STAT-46). */
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'From the droplet',
            self::External => 'From outside',
        };
    }
}
