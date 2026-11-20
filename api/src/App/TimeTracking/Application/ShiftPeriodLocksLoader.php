<?php

declare(strict_types=1);

namespace App\TimeTracking\Application;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use App\TimeTracking\Domain\Model\ShiftPeriodLocks;
use App\TimeTracking\Domain\Repository\LockedShiftPeriodRepositoryInterface;

/**
 * The organization's locked shift periods with its time zone (asked through the Organization
 * module's query), for the handlers that write shifts or shift change requests (ADR 0027 §8).
 */
final readonly class ShiftPeriodLocksLoader
{
    public function __construct(
        private LockedShiftPeriodRepositoryInterface $periods,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** @throws OrganizationNotFoundException when the actor is not an active member of the organization */
    public function load(string $organizationId, string $actorId): ShiftPeriodLocks
    {
        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($organizationId, $actorId));
        $organization ??= throw OrganizationNotFoundException::forId($organizationId);

        return ShiftPeriodLocks::of($this->periods->findAll(), new \DateTimeZone($organization->timezone));
    }
}
