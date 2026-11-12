<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\ListManagingMembers\ListManagingMembersHandler;
use App\Organization\Application\Query\ListManagingMembers\ListManagingMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ListManagingMembersHandler::class)]
#[CoversClass(Organization::class)]
final class ListManagingMembersHandlerTest extends TestCase
{
    public function test_it_lists_the_active_owners_and_managers_only(): void
    {
        $organizations = new InMemoryOrganizationRepository();
        $owner = UserMother::any('olivia@example.com');
        $manager = UserMother::any('max@example.com');
        $leaver = UserMother::any('lea@example.com');
        $organization = OrganizationMother::ownedBy($owner);
        OrganizationMother::addMember($organization, $manager, MembershipRole::Manager);
        OrganizationMother::addMember($organization, $leaver, MembershipRole::Manager);
        OrganizationMother::addMember($organization, UserMother::any('eve@example.com'), MembershipRole::Employee);
        OrganizationMother::deactivateMember($organization, $leaver);
        $organizations->add($organization);

        $members = (new ListManagingMembersHandler($organizations))(new ListManagingMembersQuery($organization->getId()->toRfc4122()));

        self::assertSame(
            [['olivia@example.com', 'owner'], ['max@example.com', 'manager']],
            array_map(static fn (MemberView $member): array => [$member->email, $member->role], $members ?? []),
        );
    }

    public function test_it_lists_nothing_for_an_unknown_organization(): void
    {
        $handler = new ListManagingMembersHandler(new InMemoryOrganizationRepository());

        self::assertNull($handler(new ListManagingMembersQuery(Uuid::v7()->toRfc4122())));
        self::assertNull($handler(new ListManagingMembersQuery('not-a-uuid')));
    }
}
