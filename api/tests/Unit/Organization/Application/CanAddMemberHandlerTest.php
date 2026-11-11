<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\CanAddMember\CanAddMemberHandler;
use App\Organization\Application\Query\CanAddMember\CanAddMemberQuery;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CanAddMemberHandler::class)]
final class CanAddMemberHandlerTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private User $owner;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any();
        $this->organization = OrganizationMother::ownedBy($this->owner);
        $this->organizations->add($this->organization);
    }

    public function test_it_allows_an_owner_and_changes_nothing(): void
    {
        self::assertTrue($this->ask($this->owner->getId()->toRfc4122(), 'owner'));
        self::assertCount(1, $this->organization->getMemberships());
    }

    public function test_it_allows_a_manager_to_add_an_employee(): void
    {
        $manager = UserMother::any('max@example.com');
        OrganizationMother::addMember($this->organization, $manager, MembershipRole::Manager);

        self::assertTrue($this->ask($manager->getId()->toRfc4122(), 'employee'));
    }

    public function test_it_refuses_a_manager_adding_a_manager(): void
    {
        $manager = UserMother::any('max@example.com');
        OrganizationMother::addMember($this->organization, $manager, MembershipRole::Manager);

        $this->expectException(RoleAssignmentForbiddenException::class);

        $this->ask($manager->getId()->toRfc4122(), 'manager');
    }

    public function test_it_refuses_an_employee(): void
    {
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($this->organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->ask($employee->getId()->toRfc4122(), 'employee');
    }

    public function test_it_returns_null_for_a_non_member_or_an_unknown_organization(): void
    {
        self::assertNull($this->ask(Uuid::v7()->toRfc4122(), 'employee'));
        self::assertNull((new CanAddMemberHandler($this->organizations))(
            new CanAddMemberQuery(Uuid::v7()->toRfc4122(), $this->owner->getId()->toRfc4122(), 'employee'),
        ));
        self::assertNull((new CanAddMemberHandler($this->organizations))(
            new CanAddMemberQuery('not-a-uuid', $this->owner->getId()->toRfc4122(), 'employee'),
        ));
    }

    private function ask(string $actorId, string $role): ?bool
    {
        return (new CanAddMemberHandler($this->organizations))(
            new CanAddMemberQuery($this->organization->getId()->toRfc4122(), $actorId, $role),
        );
    }
}
