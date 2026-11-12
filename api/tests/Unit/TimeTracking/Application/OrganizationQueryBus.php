<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\ListManagingMembers\ListManagingMembersHandler;
use App\Organization\Application\Query\ListManagingMembers\ListManagingMembersQuery;
use App\Organization\Application\Query\ListMembers\ListMembersHandler;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\StubQueryBus;

/**
 * The Organization module as the TimeTracking handlers see it: its real queries over an in-memory
 * repository, so who is an active member with which role, and who sees whom, are its own rules.
 */
final class OrganizationQueryBus
{
    public static function over(InMemoryOrganizationRepository $organizations): StubQueryBus
    {
        return new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof ListMembersQuery => (new ListMembersHandler($organizations))($query),
            $query instanceof ListManagingMembersQuery => (new ListManagingMembersHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
    }
}
