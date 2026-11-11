<?php

declare(strict_types=1);

namespace App\User\Application\Link;

/**
 * Our frontend apps, where e-mailed links open (ADR 0013). The API keeps this list itself, so a
 * caller can only pick one of them, never pass a URL that would carry a one-time secret elsewhere.
 * Base URLs are configured in AppLinks.
 */
enum ClientApp: string
{
    /** The admin panel, for owners and managers. */
    case Admin = 'admin';
    /** The time clock PWA, for employees. */
    case Timeclock = 'timeclock';
}
