<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListHourlyRates;

use App\Shared\Application\Query\QueryInterface;

/**
 * The hourly rates of the current organization (TenantFilter), by member, newest first. Owners and
 * managers see everyone's, deactivated members' included (ADR 0014), and may narrow them to one
 * member with $userId; employees see their own only, also without $userId. Returns null when the
 * actor is not an active member; throws when an employee asks for someone else's or $userId is not a
 * member (ADR 0027 §10).
 */
final readonly class ListHourlyRatesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public ?string $userId = null,
    ) {
    }
}
