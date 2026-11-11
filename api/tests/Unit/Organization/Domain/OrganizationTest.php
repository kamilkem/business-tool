<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\Exception\AlreadyAMemberException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
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
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');

        $organization->addMember($employee, MembershipRole::Employee);

        self::assertSame(MembershipRole::Employee, $organization->membershipOf($employee->getId())?->getRole());
    }

    public function test_it_refuses_to_add_the_same_user_twice(): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);

        $this->expectException(AlreadyAMemberException::class);

        $organization->addMember($owner, MembershipRole::Employee);
    }

    #[DataProvider('managingRoles')]
    public function test_it_lets_owners_and_managers_change_settings(MembershipRole $role): void
    {
        $owner = UserMother::any();
        $organization = OrganizationMother::ownedBy($owner);
        $actor = $owner;
        if (MembershipRole::Manager === $role) {
            $actor = UserMother::any('max@example.com');
            $organization->addMember($actor, $role);
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
        $organization->addMember($employee, MembershipRole::Employee);

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
        $organization->addMember($manager, MembershipRole::Manager);
        $organization->addMember(UserMother::any('eve@example.com'), MembershipRole::Employee);
        $actor = MembershipRole::Owner === $role ? $owner : $manager;

        $members = $organization->membersVisibleTo($actor->getId());

        self::assertCount(3, $members);
    }

    public function test_it_refuses_the_member_list_to_an_employee(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());
        $employee = UserMother::any('eve@example.com');
        $organization->addMember($employee, MembershipRole::Employee);

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->membersVisibleTo($employee->getId());
    }

    public function test_it_refuses_the_member_list_to_a_non_member(): void
    {
        $organization = OrganizationMother::ownedBy(UserMother::any());

        $this->expectException(OrganizationManagementForbiddenException::class);

        $organization->membersVisibleTo(Uuid::v7());
    }
}
