<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;

/**
 * The Organization module as the shift-changing handlers see it: its real member query over an
 * in-memory repository, so who is an active member with which role is its own rule.
 */
final class OrganizationQueryBus
{
    public static function over(InMemoryOrganizationRepository $organizations): StubQueryBus
    {
        return new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }
}
