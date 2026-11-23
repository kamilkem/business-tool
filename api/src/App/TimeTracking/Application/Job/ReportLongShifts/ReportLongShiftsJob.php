<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Job\ReportLongShifts;

use App\Shared\Application\Job\OrganizationJobInterface;

/**
 * Reports one organization's open shifts that run longer than its maximum shift length (#33):
 * queued by the hourly fan-out with deduplicationKey(). Idempotent: each shift is reported once, in
 * its own command and flush.
 */
final readonly class ReportLongShiftsJob implements OrganizationJobInterface
{
    public function __construct(public string $organizationId)
    {
    }

    public static function deduplicationKey(string $organizationId): string
    {
        return 'long-shift-report:'.$organizationId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }
}
