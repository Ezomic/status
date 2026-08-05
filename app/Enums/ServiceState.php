<?php

declare(strict_types=1);

namespace App\Enums;

use App\ValueObjects\ProbeResult;

enum ServiceState: string
{
    case Unknown = 'unknown';
    case Up = 'up';
    case Maintenance = 'maintenance';
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
            // Checked before the status code comparison, because a maintenance 503
            // never equals the expected 200 and would otherwise read as an outage.
            // Every app on the droplet deploys with `artisan down --retry`, so this
            // is the difference between a deploy and a real failure (STAT-18). A bare
            // 503 with no Retry-After stays Down: nothing announced it.
            $probe->statusCode === 503 && $probe->retryAfter !== null => self::Maintenance,
            $probe->statusCode !== $expectedStatusCode => self::Down,
            $probe->responseTimeMs >= $degradedThresholdMs => self::Degraded,
            default => self::Up,
        };
    }

    /**
     * Only ever used to decide whether an open incident escalates. Maintenance sits
     * with Up at zero because it is not a degree of broken; EvaluateIncident returns
     * before it can be compared anyway.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Unknown, self::Up, self::Maintenance => 0,
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
            self::Maintenance => 'Maintenance',
            self::Degraded => 'Degraded',
            self::Down => 'Down',
        };
    }
}
