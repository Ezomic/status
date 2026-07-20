<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObjects\ProbeResult;

enum ServiceState: string
{
    case Unknown = 'unknown';
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';

    /**
     * Classify a probe from scalars only.
     *
     * This deliberately takes no model and touches no HTTP: under Http::fake() the
     * transfer time is never populated, so a pure function is the only place the
     * threshold rule can actually be tested.
     */
    public static function classify(ProbeResult $probe, int $expectedStatusCode, int $degradedThresholdMs): self
    {
        return match (true) {
            $probe->error !== null => self::Down,
            $probe->statusCode !== $expectedStatusCode => self::Down,
            $probe->responseTimeMs >= $degradedThresholdMs => self::Degraded,
            default => self::Up,
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Unknown, self::Up => 0,
            self::Degraded => 1,
            self::Down => 2,
        };
    }

    public function isWorseThan(self $other): bool
    {
        return $this->severity() > $other->severity();
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not checked',
            self::Up => 'Up',
            self::Degraded => 'Degraded',
            self::Down => 'Down',
        };
    }
}
