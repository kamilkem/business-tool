<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\ChangeMemberRole\ChangeMemberRoleCommand;
use App\Organization\Application\Command\ChangeMemberRole\ChangeMemberRoleHandler;
use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ChangeMemberRoleHandler::class)]
final class ChangeMemberRoleHandlerTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private User $owner;
    private User $manager;
    private User $employee;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('max@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee);
        $this->organizations->add($this->organization);
    }

    public function test_it_changes_the_role_and_saves(): void
    {
        $this->handle($this->owner, $this->employee->getId()->toRfc4122(), 'manager');

        self::assertSame(MembershipRole::Manager, $this->organization->membershipOf($this->employee->getId())?->getRole());
        self::assertSame(1, $this->organizations->saveCallCount);
    }

    public function test_it_hides_organizations_the_actor_does_not_belong_to(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle(UserMother::any('stranger@example.com'), $this->employee->getId()->toRfc4122(), 'manager');
    }

    public function test_it_hides_an_unknown_organization(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->owner, $this->employee->getId()->toRfc4122(), 'manager', Uuid::v7()->toRfc4122());
    }

    public function test_it_refuses_an_unknown_or_malformed_user_id_as_not_a_member(): void
    {
        foreach ([Uuid::v7()->toRfc4122(), 'not-a-uuid'] as $userId) {
            try {
                $this->handle($this->owner, $userId, 'manager');
                self::fail('Expected the user to be reported as not a member.');
            } catch (MemberNotFoundException) {
            }
        }

        self::assertSame(0, $this->organizations->saveCallCount);
    }

    public function test_it_refuses_an_employee_and_saves_nothing(): void
    {
        try {
            $this->handle($this->employee, $this->employee->getId()->toRfc4122(), 'manager');
            self::fail('Expected the employee to be refused.');
        } catch (OrganizationManagementForbiddenException) {
        }

        self::assertSame(MembershipRole::Employee, $this->organization->membershipOf($this->employee->getId())?->getRole());
        self::assertSame(0, $this->organizations->saveCallCount);
    }

    public function test_it_refuses_a_manager_promoting_an_employee_and_saves_nothing(): void
    {
        try {
            $this->handle($this->manager, $this->employee->getId()->toRfc4122(), 'manager');
            self::fail('Expected the manager to be refused.');
        } catch (RoleAssignmentForbiddenException) {
        }

        self::assertSame(MembershipRole::Employee, $this->organization->membershipOf($this->employee->getId())?->getRole());
        self::assertSame(0, $this->organizations->saveCallCount);
    }

    public function test_it_refuses_to_demote_the_last_owner_and_saves_nothing(): void
    {
        try {
            $this->handle($this->owner, $this->owner->getId()->toRfc4122(), 'employee');
            self::fail('Expected the last owner to stay an owner.');
        } catch (LastOwnerException) {
        }

        self::assertSame(MembershipRole::Owner, $this->organization->membershipOf($this->owner->getId())?->getRole());
        self::assertSame(0, $this->organizations->saveCallCount);
    }

    private function handle(User $actor, string $userId, string $role, ?string $organizationId = null): void
    {
        (new ChangeMemberRoleHandler($this->organizations))(new ChangeMemberRoleCommand(
            $organizationId ?? $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $userId,
            $role,
        ));
    }
}
