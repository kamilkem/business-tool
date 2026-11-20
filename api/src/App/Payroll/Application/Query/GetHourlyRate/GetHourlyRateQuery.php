<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\GetHourlyRate;

use App\Shared\Application\Query\QueryInterface;

/**
 * One hourly rate of the current organization (TenantFilter): for an owner or manager, or for the
 * member it is for. Returns null when the rate does not exist there or the actor is not an active
 * member; throws when an employee asks about someone else's (ADR 0027 §10).
 */
final readonly class GetHourlyRateQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $rateId,
    ) {
    }
}
