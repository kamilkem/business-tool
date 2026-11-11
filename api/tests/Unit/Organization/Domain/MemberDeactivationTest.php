<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberDeactivatedException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\Membership;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Organization::class)]
#[CoversClass(Membership::class)]
final class MemberDeactivationTest extends TestCase
{
    private User $owner;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->owner = UserMother::any('olivia@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner);
    }

    public function test_it_deactivates_a_member_and_keeps_the_membership(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $now = new \DateTimeImmutable('2026-11-11 12:00:00');

        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), $now);

        $membership = $this->organization->membershipOf($employee->getId());
        self::assertNotNull($membership);
        self::assertFalse($membership->isActive());
        self::assertSame($now, $membership->getDeactivatedAt());
        self::assertSame(MembershipRole::Employee, $membership->getRole());
        self::assertFalse($this->organization->isActiveMember($employee->getId()));
        self::assertTrue($this->organization->isActiveMember($this->owner->getId()));
    }

    public function test_it_keeps_the_first_deactivation_time_when_deactivated_again(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $first = new \DateTimeImmutable('2026-11-11 12:00:00');

        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), $first);
        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), new \DateTimeImmutable('2026-11-12 12:00:00'));

        self::assertSame($first, $this->organization->membershipOf($employee->getId())?->getDeactivatedAt());
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, bool}> */
    public static function deactivations(): iterable
    {
        yield 'owner deactivates an owner' => [MembershipRole::Owner, MembershipRole::Owner, true];
        yield 'owner deactivates a manager' => [MembershipRole::Owner, MembershipRole::Manager, true];
        yield 'owner deactivates an employee' => [MembershipRole::Owner, MembershipRole::Employee, true];
        yield 'manager deactivates an owner' => [MembershipRole::Manager, MembershipRole::Owner, false];
        yield 'manager deactivates a manager' => [MembershipRole::Manager, MembershipRole::Manager, false];
        yield 'manager deactivates an employee' => [MembershipRole::Manager, MembershipRole::Employee, true];
    }

    #[DataProvider('deactivations')]
    public function test_it_lets_owners_deactivate_anyone_and_managers_only_employees(
        MembershipRole $actorRole,
        MembershipRole $targetRole,
        bool $allowed,
    ): void {
        $actor = MembershipRole::Owner === $actorRole ? $this->owner : $this->member('actor@example.com', $actorRole);
        $target = $this->member('target@example.com', $targetRole);

        try {
            $this->organization->deactivateMember($actor->getId(), $target->getId(), new \DateTimeImmutable());
            self::assertTrue($allowed, 'Expected the deactivation to be refused.');
        } catch (RoleAssignmentForbiddenException) {
            self::assertFalse($allowed, 'Expected the deactivation to be allowed.');
        }

        self::assertSame(!$allowed, $this->organization->isActiveMember($target->getId()));
    }

    public function test_it_refuses_a_deactivation_from_an_employee(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->organization->deactivateMember($employee->getId(), $employee->getId(), new \DateTimeImmutable());
    }

    public function test_it_refuses_a_deactivation_from_a_non_member(): void
    {
        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->organization->deactivateMember(Uuid::v7(), $this->owner->getId(), new \DateTimeImmutable());
    }

    public function test_it_refuses_to_deactivate_a_user_who_is_not_a_member(): void
    {
        $this->expectException(MemberNotFoundException::class);

        $this->organization->deactivateMember($this->owner->getId(), Uuid::v7(), new \DateTimeImmutable());
    }

    public function test_it_refuses_to_let_the_last_owner_deactivate_themselves(): void
    {
        $this->member('max@example.com', MembershipRole::Manager);

        try {
            $this->organization->deactivateMember($this->owner->getId(), $this->owner->getId(), new \DateTimeImmutable());
            self::fail('Expected the last owner to stay active.');
        } catch (LastOwnerException) {
        }

        self::assertTrue($this->organization->isActiveMember($this->owner->getId()));
    }

    public function test_it_counts_only_active_owners_when_keeping_the_last_one(): void
    {
        $coOwner = $this->member('otto@example.com', MembershipRole::Owner);
        $this->organization->deactivateMember($this->owner->getId(), $coOwner->getId(), new \DateTimeImmutable());

        $this->expectException(LastOwnerException::class);

        $this->organization->deactivateMember($this->owner->getId(), $this->owner->getId(), new \DateTimeImmutable());
    }

    public function test_it_lets_an_owner_deactivate_themselves_while_another_owner_remains(): void
    {
        $coOwner = $this->member('otto@example.com', MembershipRole::Owner);

        $this->organization->deactivateMember($this->owner->getId(), $this->owner->getId(), new \DateTimeImmutable());

        self::assertFalse($this->organization->isActiveMember($this->owner->getId()));
        self::assertTrue($this->organization->isActiveMember($coOwner->getId()));
    }

    public function test_it_refuses_to_demote_the_last_active_owner(): void
    {
        $coOwner = $this->member('otto@example.com', MembershipRole::Owner);
        $this->organization->deactivateMember($this->owner->getId(), $coOwner->getId(), new \DateTimeImmutable());

        $this->expectException(LastOwnerException::class);

        $this->organization->changeMemberRole($this->owner->getId(), $this->owner->getId(), MembershipRole::Manager);
    }

    public function test_it_takes_management_rights_from_a_deactivated_manager(): void
    {
        $manager = $this->member('max@example.com', MembershipRole::Manager);
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $this->organization->deactivateMember($this->owner->getId(), $manager->getId(), new \DateTimeImmutable());

        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->organization->deactivateMember($manager->getId(), $employee->getId(), new \DateTimeImmutable());
    }

    public function test_it_hides_deactivated_members_from_the_list_unless_asked(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), new \DateTimeImmutable());

        self::assertSame([$this->owner], self::users($this->organization->membersVisibleTo($this->owner->getId())));
        self::assertSame([$this->owner, $employee], self::users($this->organization->membersVisibleTo($this->owner->getId(), includeDeactivated: true)));
    }

    public function test_it_refuses_to_add_a_deactivated_member_again(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), new \DateTimeImmutable());

        $this->expectException(MemberDeactivatedException::class);

        $this->organization->addMember($this->owner->getId(), $employee, MembershipRole::Manager);
    }

    public function test_it_refuses_to_change_the_role_of_a_deactivated_member(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), new \DateTimeImmutable());

        $this->expectException(MemberDeactivatedException::class);

        $this->organization->changeMemberRole($this->owner->getId(), $employee->getId(), MembershipRole::Manager);
    }

    public function test_it_refuses_to_act_on_a_deactivated_member(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);
        $this->organization->deactivateMember($this->owner->getId(), $employee->getId(), new \DateTimeImmutable());

        $this->expectException(MemberDeactivatedException::class);

        $this->organization->manageableMembership($this->owner->getId(), $employee->getId());
    }

    public function test_it_reactivates_a_member_with_the_role_they_had(): void
    {
        $manager = $this->member('max@example.com', MembershipRole::Manager);
        $this->organization->deactivateMember($this->owner->getId(), $manager->getId(), new \DateTimeImmutable());

        $this->organization->reactivateMember($this->owner->getId(), $manager->getId());

        $membership = $this->organization->membershipOf($manager->getId());
        self::assertNotNull($membership);
        self::assertTrue($membership->isActive());
        self::assertNull($membership->getDeactivatedAt());
        self::assertSame(MembershipRole::Manager, $membership->getRole());
    }

    public function test_it_changes_nothing_when_reactivating_an_active_member(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);

        $this->organization->reactivateMember($this->owner->getId(), $employee->getId());

        self::assertTrue($this->organization->isActiveMember($employee->getId()));
    }

    #[DataProvider('deactivations')]
    public function test_it_lets_owners_reactivate_anyone_and_managers_only_employees(
        MembershipRole $actorRole,
        MembershipRole $targetRole,
        bool $allowed,
    ): void {
        $actor = MembershipRole::Owner === $actorRole ? $this->owner : $this->member('actor@example.com', $actorRole);
        $target = $this->member('target@example.com', $targetRole);
        $this->organization->deactivateMember($this->owner->getId(), $target->getId(), new \DateTimeImmutable());

        try {
            $this->organization->reactivateMember($actor->getId(), $target->getId());
            self::assertTrue($allowed, 'Expected the reactivation to be refused.');
        } catch (RoleAssignmentForbiddenException) {
            self::assertFalse($allowed, 'Expected the reactivation to be allowed.');
        }

        self::assertSame($allowed, $this->organization->isActiveMember($target->getId()));
    }

    public function test_it_refuses_a_reactivation_from_an_employee(): void
    {
        $employee = $this->member('eve@example.com', MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->organization->reactivateMember($employee->getId(), $employee->getId());
    }

    public function test_it_refuses_to_reactivate_a_user_who_is_not_a_member(): void
    {
        $this->expectException(MemberNotFoundException::class);

        $this->organization->reactivateMember($this->owner->getId(), Uuid::v7());
    }

    private function member(string $email, MembershipRole $role): User
    {
        $user = UserMother::any($email);
        OrganizationMother::addMember($this->organization, $user, $role);

        return $user;
    }

    /**
     * @param list<Membership> $memberships
     *
     * @return list<User>
     */
    private static function users(array $memberships): array
    {
        return array_map(static fn (Membership $membership): User => $membership->getUser(), $memberships);
    }
}
