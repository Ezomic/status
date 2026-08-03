<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three transitions worth an email. Anything else that touches an incident,
 * including the housekeeping close when a service is paused, deliberately has
 * no member here: a monitoring pause is not a recovery and must not be
 * announced as one.
 */
enum IncidentChange: string
{
    case Opened = 'opened';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
}
