<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Exception\AlreadyAMemberException;
use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Organization::class)]
final class OrganizationTest extends TestCase
{
    public function test_it_makes_the_creator_its_owner(): void
    {
        $owner = UserMother::any();

        $organization = OrganizationMother::ownedBy($owner);

        self::assertSame(MembershipRole::Owner, $organization->membershipOf($owner->getId())?->getRole());
    }

    public function test_it_normalizes_settings_on_creation(): void
    {
        $organization = new Organization(Uuid::v7(), 'Corner Pub', UserMother::any(), '  ', 'pl', 'pln', 'Europe/Warsaw');

        self::assertNull($organization->getTaxId());
        self::assertSame('PL', $organization->getCountryCode());
        self::assertSame('PLN', $organization->getCurrency());
    }

    public function test_it_adds_members_with_a_role(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $employee = UserMother::any('eve@example.com');

        $membership = $organization->addMember($owner->getId(), $employee, MembershipRole::Employee);

        self::assertSame($membership, $organization->membershipOf($employee->getId()));
        self::assertSame(MembershipRole::Employee, $membership->getRole());
    }

    public function test_it_refuses_to_add_the_same_user_twice(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $this->expectException(AlreadyAMemberException::class);

        $organization->addMember($owner->getId(), $owner, MembershipRole::Employee);
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, bool}> */
    public static function memberAdditions(): iterable
    {
        yield 'owner adds an owner' => [MembershipRole::Owner, MembershipRole::Owner, true];
        yield 'owner adds a manager' => [MembershipRole::Owner, MembershipRole::Manager, true];
        yield 'owner adds an employee' => [MembershipRole::Owner, MembershipRole::Employee, true];
        yield 'manager adds an owner' => [MembershipRole::Manager, MembershipRole::Owner, false];
        yield 'manager adds a manager' => [MembershipRole::Manager, MembershipRole::Manager, false];
        yield 'manager adds an employee' => [MembershipRole::Manager, MembershipRole::Employee, true];
    }

    #[DataProvider('memberAdditions')]
    public function test_it_lets_owners_add_any_role_and_managers_only_employees(
        MembershipRole $actorRole,
        MembershipRole $role,
        bool $allowed,
    ): void {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $actor = $owner;
        if (MembershipRole::Owner !== $actorRole) {
            $actor = UserMother::any('max@example.com');
            OrganizationMother::addMember($organization, $actor, $actorRole);
        }
        $newcomer = UserMother::any('new@example.com');

        if (!$allowed) {
            $this->expectException(RoleAssignmentForbiddenException::class);
        }

        $organization->addMember($actor->getId(), $newcomer, $role);

        self::assertSame($role, $organization->membershipOf($newcomer->getId())?->getRole());
    }

    public function test_it_refuses_member_additions_from_an_employee(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->addMember($employee->getId(), UserMother::any('new@example.com'), MembershipRole::Employee);
    }

    public function test_it_refuses_member_additions_from_a_non_member(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->addMember(Uuid::v7(), UserMother::any('new@example.com'), MembershipRole::Employee);
    }

    public function test_it_checks_the_permission_before_the_existing_membership(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $manager = UserMother::any('max@example.com');
        OrganizationMother::addMember($organization, $manager, MembershipRole::Manager);

        $this->expectException(RoleAssignmentForbiddenException::class);

        $organization->addMember($manager->getId(), $owner, MembershipRole::Manager);
    }

    public function test_it_checks_a_member_addition_without_adding_anyone(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $organization->assertCanAddMember($owner->getId(), MembershipRole::Manager);

        self::assertCount(1, $organization->getMemberships());
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_change_settings(MembershipRole $role): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $actor = $owner;
        if (MembershipRole::Manager === $role) {
            $actor = UserMother::any('max@example.com');
            OrganizationMother::addMember($organization, $actor, $role);
        }

        $organization->changeSettings($actor->getId(), 'Renamed', ' 1234567890 ', 'de', 'eur', 'Europe/Berlin');

        self::assertSame('Renamed', $organization->getName());
        self::assertSame('1234567890', $organization->getTaxId());
        self::assertSame('DE', $organization->getCountryCode());
        self::assertSame('EUR', $organization->getCurrency());
        self::assertSame('Europe/Berlin', $organization->getTimezone());
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    public function test_it_refuses_settings_changes_from_an_employee(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->changeSettings($employee->getId(), 'Renamed', null, 'PL', 'PLN', 'Europe/Warsaw');
    }

    public function test_it_refuses_settings_changes_from_a_non_member(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->changeSettings(Uuid::v7(), 'Renamed', null, 'PL', 'PLN', 'Europe/Warsaw');
    }

    #[DataProvider('managingRoles')]
    public function test_it_shows_every_member_to_owners_and_managers(MembershipRole $role): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $manager = UserMother::any('max@example.com');
        OrganizationMother::addMember($organization, $manager, MembershipRole::Manager);
        OrganizationMother::addMember($organization, UserMother::any('eve@example.com'), MembershipRole::Employee);
        $actor = MembershipRole::Owner === $role ? $owner : $manager;

        $members = $organization->membersVisibleTo($actor->getId());

        self::assertCount(3, $members);
    }

    public function test_it_refuses_the_member_list_to_an_employee(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->membersVisibleTo($employee->getId());
    }

    public function test_it_refuses_the_member_list_to_a_non_member(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->membersVisibleTo(Uuid::v7());
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, bool}> */
    public static function memberManagement(): iterable
    {
        yield 'owner acts on an owner' => [MembershipRole::Owner, MembershipRole::Owner, true];
        yield 'owner acts on a manager' => [MembershipRole::Owner, MembershipRole::Manager, true];
        yield 'owner acts on an employee' => [MembershipRole::Owner, MembershipRole::Employee, true];
        yield 'manager acts on a manager' => [MembershipRole::Manager, MembershipRole::Manager, false];
        yield 'manager acts on an employee' => [MembershipRole::Manager, MembershipRole::Employee, true];
    }

    #[DataProvider('memberManagement')]
    public function test_it_lets_owners_act_on_any_member_and_managers_only_on_employees(
        MembershipRole $actorRole,
        MembershipRole $targetRole,
        bool $allowed,
    ): void {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $actor = $owner;
        if (MembershipRole::Owner !== $actorRole) {
            $actor = UserMother::any('actor@example.com');
            OrganizationMother::addMember($organization, $actor, $actorRole);
        }
        $target = UserMother::any('target@example.com');
        OrganizationMother::addMember($organization, $target, $targetRole);

        if (!$allowed) {
            $this->expectException(RoleAssignmentForbiddenException::class);
        }

        self::assertSame($target, $organization->manageableMembership($actor->getId(), $target->getId())->getUser());
    }

    public function test_it_refuses_to_act_on_a_user_who_is_not_a_member(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $this->expectException(MemberNotFoundException::class);

        $organization->manageableMembership($owner->getId(), Uuid::v7());
    }

    public function test_it_refuses_an_employee_acting_on_a_member(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->manageableMembership($employee->getId(), $employee->getId());
    }

    /** @return iterable<string, array{MembershipRole, MembershipRole, MembershipRole, bool}> */
    public static function roleChanges(): iterable
    {
        foreach (MembershipRole::cases() as $target) {
            foreach (MembershipRole::cases() as $role) {
                yield \sprintf('owner makes a %s %s', $target->value, $role->value) => [MembershipRole::Owner, $target, $role, true];
                yield \sprintf('manager makes a %s %s', $target->value, $role->value) => [
                    MembershipRole::Manager,
                    $target,
                    $role,
                    MembershipRole::Employee === $target && MembershipRole::Employee === $role,
                ];
            }
        }
    }

    #[DataProvider('roleChanges')]
    public function test_it_lets_owners_change_any_role_and_managers_only_keep_employees_as_employees(
        MembershipRole $actorRole,
        MembershipRole $targetRole,
        MembershipRole $role,
        bool $allowed,
    ): void {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $actor = $owner;
        if (MembershipRole::Owner !== $actorRole) {
            $actor = UserMother::any('actor@example.com');
            OrganizationMother::addMember($organization, $actor, $actorRole);
        }
        $target = UserMother::any('target@example.com');
        OrganizationMother::addMember($organization, $target, $targetRole);

        try {
            $organization->changeMemberRole($actor->getId(), $target->getId(), $role);
            self::assertTrue($allowed, 'Expected the change to be refused.');
            self::assertSame($role, $organization->membershipOf($target->getId())?->getRole());
        } catch (RoleAssignmentForbiddenException) {
            self::assertFalse($allowed, 'Expected the change to be allowed.');
            self::assertSame($targetRole, $organization->membershipOf($target->getId())?->getRole());
        }
    }

    public function test_it_refuses_role_changes_from_an_employee(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->changeMemberRole($employee->getId(), $employee->getId(), MembershipRole::Manager);
    }

    public function test_it_refuses_role_changes_from_a_non_member(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->changeMemberRole(Uuid::v7(), $owner->getId(), MembershipRole::Employee);
    }

    public function test_it_refuses_to_change_the_role_of_a_user_who_is_not_a_member(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $this->expectException(MemberNotFoundException::class);

        $organization->changeMemberRole($owner->getId(), Uuid::v7(), MembershipRole::Manager);
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function demotions(): iterable
    {
        yield 'to manager' => [MembershipRole::Manager];
        yield 'to employee' => [MembershipRole::Employee];
    }

    #[DataProvider('demotions')]
    public function test_it_refuses_to_demote_the_last_owner(MembershipRole $role): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        OrganizationMother::addMember($organization, UserMother::any('max@example.com'), MembershipRole::Manager);

        try {
            $organization->changeMemberRole($owner->getId(), $owner->getId(), $role);
            self::fail('Expected the last owner to stay an owner.');
        } catch (LastOwnerException) {
        }

        self::assertSame(MembershipRole::Owner, $organization->membershipOf($owner->getId())?->getRole());
    }

    public function test_it_keeps_the_last_owner_an_owner_when_their_role_is_set_to_owner_again(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $organization->changeMemberRole($owner->getId(), $owner->getId(), MembershipRole::Owner);

        self::assertSame(MembershipRole::Owner, $organization->membershipOf($owner->getId())?->getRole());
    }

    #[DataProvider('demotions')]
    public function test_it_lets_an_owner_step_down_when_another_owner_remains(MembershipRole $role): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $coOwner = UserMother::any('otto@example.com');
        OrganizationMother::addMember($organization, $coOwner, MembershipRole::Owner);

        $organization->changeMemberRole($owner->getId(), $owner->getId(), $role);

        self::assertSame($role, $organization->membershipOf($owner->getId())?->getRole());
        // The remaining owner is now the last one.
        $this->expectException(LastOwnerException::class);
        $organization->changeMemberRole($coOwner->getId(), $coOwner->getId(), $role);
    }

    public function test_it_lets_an_owner_demote_another_owner_when_one_remains(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $coOwner = UserMother::any('otto@example.com');
        OrganizationMother::addMember($organization, $coOwner, MembershipRole::Owner);

        $organization->changeMemberRole($owner->getId(), $coOwner->getId(), MembershipRole::Employee);

        self::assertSame(MembershipRole::Employee, $organization->membershipOf($coOwner->getId())?->getRole());
    }

    public function test_it_shows_a_member_their_own_membership_whatever_their_role(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        self::assertSame($employee, $organization->memberVisibleTo($employee->getId(), $employee->getId())?->getUser());
    }

    #[DataProvider('managingRoles')]
    public function test_it_shows_any_member_to_owners_and_managers(MembershipRole $role): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $actor = $owner;
        if (MembershipRole::Manager === $role) {
            $actor = UserMother::any('max@example.com');
            OrganizationMother::addMember($organization, $actor, $role);
        }
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        self::assertSame($employee, $organization->memberVisibleTo($actor->getId(), $employee->getId())?->getUser());
        self::assertNull($organization->memberVisibleTo($actor->getId(), Uuid::v7()));
    }

    public function test_it_hides_other_members_from_an_employee(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $employee = UserMother::any('eve@example.com');
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->memberVisibleTo($employee->getId(), $owner->getId());
    }
}
