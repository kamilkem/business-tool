<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\ListMembers\ListMembersHandler;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ListMembersHandler::class)]
#[CoversClass(MemberView::class)]
final class ListMembersHandlerTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private User $owner;
    private User $manager;
    private User $employee;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = self::user('olivia@example.com', 'Olivia', 'Zielińska');
        $this->manager = self::user('max@example.com', 'Max', 'Adamski');
        $this->employee = self::user('eve@example.com', 'Eve', 'Nowak', hasPassword: false);
        $this->organization = OrganizationMother::ownedBy($this->owner);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee);
        $this->organizations->add($this->organization);
    }

    /** @return iterable<string, array{string}> */
    public static function managingRoles(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managingRoles')]
    public function test_it_lists_every_member_to_owners_and_managers_by_last_name(string $role): void
    {
        $actor = 'owner' === $role ? $this->owner : $this->manager;

        $members = $this->list($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122());

        self::assertSame(['Adamski', 'Nowak', 'Zielińska'], array_map(static fn (MemberView $m): string => $m->lastName, $members ?? []));
    }

    public function test_it_describes_each_member(): void
    {
        $members = $this->list($this->organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122()) ?? [];
        $byEmail = array_column($members, null, 'email');
        $owner = $byEmail['olivia@example.com'];
        $employee = $byEmail['eve@example.com'];

        self::assertSame($this->owner->getId()->toRfc4122(), $owner->userId);
        self::assertSame(['Olivia', 'Zielińska', 'owner', false], [$owner->firstName, $owner->lastName, $owner->role, $owner->invitationPending]);
        self::assertEquals($this->organization->membershipOf($this->owner->getId())?->getCreatedAt(), $owner->joinedAt);
        self::assertSame(['employee', true], [$employee->role, $employee->invitationPending]);
    }

    public function test_it_refuses_the_list_to_an_employee(): void
    {
        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->list($this->organization->getId()->toRfc4122(), $this->employee->getId()->toRfc4122());
    }

    public function test_it_returns_nothing_to_a_non_member(): void
    {
        self::assertNull($this->list($this->organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122()));
    }

    public function test_it_returns_nothing_for_an_unknown_organization(): void
    {
        self::assertNull($this->list(Uuid::v7()->toRfc4122(), $this->owner->getId()->toRfc4122()));
    }

    public function test_it_returns_nothing_for_malformed_ids(): void
    {
        self::assertNull($this->list('not-a-uuid', $this->owner->getId()->toRfc4122()));
        self::assertNull($this->list($this->organization->getId()->toRfc4122(), 'not-a-uuid'));
    }

    /** @return list<MemberView>|null */
    private function list(string $organizationId, string $userId): ?array
    {
        return (new ListMembersHandler($this->organizations))(new ListMembersQuery($organizationId, $userId));
    }

    private static function user(string $email, string $firstName, string $lastName, bool $hasPassword = true): User
    {
        $user = new User(Uuid::v7(), $email, $firstName, $lastName);
        if ($hasPassword) {
            $user->changePassword('hashed');
        }

        return $user;
    }
}
