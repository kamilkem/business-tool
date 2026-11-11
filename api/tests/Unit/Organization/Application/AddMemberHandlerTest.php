<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\AddMember\AddMemberCommand;
use App\Organization\Application\Command\AddMember\AddMemberHandler;
use App\Organization\Domain\Event\MemberAdded;
use App\Organization\Domain\Exception\AlreadyAMemberException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\User\Application\InMemoryUserRepository;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(AddMemberHandler::class)]
#[CoversClass(MemberAdded::class)]
final class AddMemberHandlerTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private InMemoryUserRepository $users;
    private RecordingEventPublisher $events;
    private User $owner;
    private User $newcomer;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->users = new InMemoryUserRepository();
        $this->events = new RecordingEventPublisher();
        $this->owner = UserMother::any('olivia@example.com');
        $this->newcomer = UserMother::any('eve@example.com');
        $this->users->add($this->owner);
        $this->users->add($this->newcomer);
        $this->organization = OrganizationMother::ownedBy($this->owner);
        $this->organizations->add($this->organization);
    }

    public function test_it_adds_the_member_saves_and_publishes_member_added(): void
    {
        $this->handle($this->owner, $this->newcomer->getId()->toRfc4122(), 'manager');

        self::assertSame(MembershipRole::Manager, $this->organization->membershipOf($this->newcomer->getId())?->getRole());
        self::assertSame(1, $this->organizations->saveCallCount);
        self::assertEquals([new MemberAdded(
            organizationId: $this->organization->getId()->toRfc4122(),
            userId: $this->newcomer->getId()->toRfc4122(),
            role: 'manager',
            addedById: $this->owner->getId()->toRfc4122(),
        )], $this->events->events);
    }

    public function test_it_hides_organizations_the_actor_does_not_belong_to(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle(UserMother::any('stranger@example.com'), $this->newcomer->getId()->toRfc4122(), 'employee');
    }

    public function test_it_refuses_an_employee_and_publishes_nothing(): void
    {
        $employee = UserMother::any('emma@example.com');
        OrganizationMother::addMember($this->organization, $employee, MembershipRole::Employee);

        try {
            $this->handle($employee, $this->newcomer->getId()->toRfc4122(), 'employee');
            self::fail('Expected the employee to be refused.');
        } catch (OrganizationManagementForbiddenException) {
        }

        self::assertNull($this->organization->membershipOf($this->newcomer->getId()));
        self::assertSame([0, []], [$this->organizations->saveCallCount, $this->events->events]);
    }

    public function test_it_refuses_a_manager_adding_a_manager(): void
    {
        $manager = UserMother::any('max@example.com');
        OrganizationMother::addMember($this->organization, $manager, MembershipRole::Manager);

        $this->expectException(RoleAssignmentForbiddenException::class);

        $this->handle($manager, $this->newcomer->getId()->toRfc4122(), 'manager');
    }

    public function test_it_refuses_an_existing_member(): void
    {
        OrganizationMother::addMember($this->organization, $this->newcomer, MembershipRole::Employee);

        $this->expectException(AlreadyAMemberException::class);

        $this->handle($this->owner, $this->newcomer->getId()->toRfc4122(), 'employee');
    }

    public function test_it_refuses_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->handle($this->owner, Uuid::v7()->toRfc4122(), 'employee');
    }

    private function handle(User $actor, string $userId, string $role): void
    {
        (new AddMemberHandler($this->organizations, $this->users, $this->events))(new AddMemberCommand(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $userId,
            $role,
        ));
    }
}
