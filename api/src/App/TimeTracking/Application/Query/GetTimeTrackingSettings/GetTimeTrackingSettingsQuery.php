<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Query\GetTimeTrackingSettings;

use App\Shared\Application\Query\QueryInterface;

/**
 * The current organization's time tracking settings (#33), for any active member: the time clock
 * warns with them. The defaults while the organization has no settings. Returns null when the actor
 * is not an active member. With `toManage`, an employee is refused (403), for a write that starts
 * from the current settings (the PUT, so it is refused before its body is validated).
 */
final readonly class GetTimeTrackingSettingsQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public bool $toManage = false,
    ) {
    }
}
