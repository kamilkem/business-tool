<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Unit\Shared\Application\StubQueryBus;

/** The Organization module as the TimeTracking handlers see it: who is an active member of what. */
final class MembershipQueryBus
{
    /** @param list<string> $activeMemberIds members of $organizationId; anyone else is refused */
    public static function of(string $organizationId, array $activeMemberIds): StubQueryBus
    {
        return new StubQueryBus(static function (QueryInterface $query) use ($organizationId, $activeMemberIds): ?OrganizationView {
            if (!$query instanceof GetOrganizationQuery) {
                throw new \LogicException(\sprintf('Unexpected query %s.', $query::class));
            }

            if ($query->organizationId !== $organizationId || !\in_array($query->userId, $activeMemberIds, true)) {
                return null;
            }

            return new OrganizationView($organizationId, 'Corner Pub', null, 'PL', 'PLN', 'Europe/Warsaw', new \DateTimeImmutable('2026-11-01 09:00:00'));
        });
    }
}
