<?php

declare(strict_types=1);

namespace App\Payroll\Application\Query\ListDayRates;

use App\Shared\Application\Query\QueryInterface;

/**
 * The day rates of the current organization (TenantFilter), by member, newest first; who sees whose
 * is as for ListHourlyRatesQuery. Returns null when the actor is not an active member; throws when
 * an employee asks for someone else's or $userId is not a member (ADR 0027 §10).
 */
final readonly class ListDayRatesQuery implements QueryInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public ?string $userId = null,
    ) {
    }
}
