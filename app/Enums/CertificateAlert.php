<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two certificate states worth telling someone about (STAT-38).
 *
 * Recorded per service so the daily refresh alerts on the transition into a state rather
 * than on the state persisting. Without that, certificates:refresh would mail every
 * morning for the last month of a certificate's life.
 *
 * "Unknown" is deliberately not a member. STAT-23 nulls the expiry when a lookup fails so
 * it reads as unknown rather than as still-fine, and an unknown expiry must never alert.
 */
enum CertificateAlert: string
{
    case Expiring = 'expiring';
    case Expired = 'expired';
}
