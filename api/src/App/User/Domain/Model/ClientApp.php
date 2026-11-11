<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

/** Our frontend apps, where e-mailed links open (ADR 0013). */
enum ClientApp: string
{
    /** The admin panel, for owners and managers. */
    case Admin = 'admin';
    /** The time clock PWA, for employees. */
    case Timeclock = 'timeclock';
}
