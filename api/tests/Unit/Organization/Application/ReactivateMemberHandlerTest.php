<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Command\ReactivateMember\ReactivateMemberCommand;
use App\Organization\Application\Command\ReactivateMember\ReactivateMemberHandler;
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

#[CoversClass(ReactivateMemberHandler::class)]
final class ReactivateMemberHandlerTest extends TestCase
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
        OrganizationMother::deactivateMember($this->organization, $this->employee);
        $this->organizations->add($this->organization);
    }

    public function test_it_reactivates_the_member_and_saves(): void
    {
        $this->handle($this->manager, $this->employee->getId()->toRfc4122());

        self::assertTrue($this->organization->isActiveMember($this->employee->getId()));
        self::assertSame(1, $this->organizations->saveCallCount);
    }

    public function test_it_hides_organizations_the_actor_does_not_belong_to(): void
    {
        $this->expectRefusal(OrganizationNotFoundException::class, UserMother::any('stranger@example.com'), $this->employee->getId()->toRfc4122());
    }

    public function test_it_does_not_let_a_deactivated_member_reactivate_themselves(): void
    {
        $this->expectRefusal(OrganizationNotFoundException::class, $this->employee, $this->employee->getId()->toRfc4122());
    }

    public function test_it_refuses_an_unknown_or_malformed_user_id_as_not_a_member(): void
    {
        $this->expectRefusal(MemberNotFoundException::class, $this->owner, Uuid::v7()->toRfc4122());
        $this->expectRefusal(MemberNotFoundException::class, $this->owner, 'not-a-uuid');
    }

    public function test_it_refuses_a_manager_reactivating_a_manager(): void
    {
        OrganizationMother::deactivateMember($this->organization, $this->manager);
        $otherManager = UserMother::any('mia@example.com');
        OrganizationMother::addMember($this->organization, $otherManager, MembershipRole::Manager);

        $this->expectRefusal(RoleAssignmentForbiddenException::class, $otherManager, $this->manager->getId()->toRfc4122());
    }

    public function test_it_refuses_an_employee(): void
    {
        $colleague = UserMother::any('carl@example.com');
        OrganizationMother::addMember($this->organization, $colleague, MembershipRole::Employee);

        $this->expectRefusal(OrganizationManagementForbiddenException::class, $colleague, $this->employee->getId()->toRfc4122());
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
    }

    private function handle(User $actor, string $userId): void
    {
        (new ReactivateMemberHandler($this->organizations))(new ReactivateMemberCommand(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $userId,
        ));
    }
}
