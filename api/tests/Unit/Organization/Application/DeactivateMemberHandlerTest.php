<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\DeactivateMember\DeactivateMemberCommand;
use App\Organization\Application\Command\DeactivateMember\DeactivateMemberHandler;
use App\Organization\Domain\Event\MemberDeactivated;
use App\Organization\Domain\Exception\LastOwnerException;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Exception\RoleAssignmentForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(DeactivateMemberHandler::class)]
final class DeactivateMemberHandlerTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    private InMemoryOrganizationRepository $organizations;
    private RecordingEventPublisher $events;
    private User $owner;
    private User $manager;
    private User $employee;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->events = new RecordingEventPublisher();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('max@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee);
        $this->organizations->add($this->organization);
    }

    public function test_it_deactivates_the_member_saves_and_publishes_member_deactivated(): void
    {
        $this->handle($this->manager, $this->employee->getId()->toRfc4122());

        $membership = $this->organization->membershipOf($this->employee->getId());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $membership?->getDeactivatedAt());
        self::assertSame(1, $this->organizations->saveCallCount);
        self::assertEquals([new MemberDeactivated(
            organizationId: $this->organization->getId()->toRfc4122(),
            userId: $this->employee->getId()->toRfc4122(),
            deactivatedById: $this->manager->getId()->toRfc4122(),
        )], $this->events->events);
    }

    public function test_it_hides_organizations_the_actor_does_not_belong_to(): void
    {
        $this->expectRefusal(OrganizationNotFoundException::class, UserMother::any('stranger@example.com'), $this->employee->getId()->toRfc4122());
    }

    public function test_it_hides_the_organization_from_a_deactivated_actor(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->manager);

        $this->expectRefusal(OrganizationNotFoundException::class, $this->manager, $this->employee->getId()->toRfc4122());
    }

    public function test_it_hides_an_unknown_organization(): void
    {
        $this->expectException(OrganizationNotFoundException::class);

        $this->handle($this->owner, $this->employee->getId()->toRfc4122(), Uuid::v7()->toRfc4122());
    }

    public function test_it_refuses_an_unknown_or_malformed_user_id_as_not_a_member(): void
    {
        $this->expectRefusal(MemberNotFoundException::class, $this->owner, Uuid::v7()->toRfc4122());
        $this->expectRefusal(MemberNotFoundException::class, $this->owner, 'not-a-uuid');
    }

    public function test_it_refuses_an_employee(): void
    {
        $this->expectRefusal(OrganizationManagementForbiddenException::class, $this->employee, $this->employee->getId()->toRfc4122());
    }

    public function test_it_refuses_a_manager_deactivating_a_manager(): void
    {
        $this->expectRefusal(RoleAssignmentForbiddenException::class, $this->manager, $this->manager->getId()->toRfc4122());
    }

    public function test_it_refuses_to_deactivate_the_last_owner(): void
    {
        $this->expectRefusal(LastOwnerException::class, $this->owner, $this->owner->getId()->toRfc4122());

        self::assertTrue($this->organization->isActiveMember($this->owner->getId()));
    }

    /** @param class-string<\Throwable> $exception */
    private function expectRefusal(string $exception, User $actor, string $userId): void
    {
        try {
            $this->handle($actor, $userId);
            self::fail(\sprintf('Expected %s.', $exception));
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }

        self::assertSame(0, $this->organizations->saveCallCount);
        self::assertSame([], $this->events->events);
    }

    private function handle(User $actor, string $userId, ?string $organizationId = null): void
    {
        (new DeactivateMemberHandler($this->organizations, $this->events, new MockClock(self::NOW)))(new DeactivateMemberCommand(
            $organizationId ?? $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $userId,
        ));
    }
}
