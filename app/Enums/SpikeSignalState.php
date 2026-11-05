<?php

declare(strict_types=1);

namespace App\Enums;

enum SpikeSignalState: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
