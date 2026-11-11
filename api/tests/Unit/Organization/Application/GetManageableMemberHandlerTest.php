<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\GetManageableMember\GetManageableMemberHandler;
use App\Organization\Application\Query\GetManageableMember\GetManageableMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\MemberNotFoundException;
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

#[CoversClass(GetManageableMemberHandler::class)]
final class GetManageableMemberHandlerTest extends TestCase
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

    public function test_it_returns_a_member_the_actor_may_act_on(): void
    {
        $view = $this->ask($this->manager, $this->employee->getId()->toRfc4122());

        self::assertNotNull($view);
        self::assertSame([$this->employee->getId()->toRfc4122(), 'employee'], [$view->userId, $view->role]);
    }

    public function test_it_returns_null_for_an_unknown_organization_or_an_actor_who_is_not_a_member(): void
    {
        self::assertNull($this->ask($this->owner, $this->employee->getId()->toRfc4122(), Uuid::v7()->toRfc4122()));
        self::assertNull($this->ask(UserMother::any('stranger@example.com'), $this->employee->getId()->toRfc4122()));
    }

    public function test_it_refuses_a_user_who_is_not_a_member(): void
    {
        $this->expectException(MemberNotFoundException::class);

        $this->ask($this->owner, Uuid::v7()->toRfc4122());
    }

    public function test_it_refuses_an_invalid_user_id_as_not_a_member(): void
    {
        $this->expectException(MemberNotFoundException::class);

        $this->ask($this->owner, 'not-a-uuid');
    }

    public function test_it_refuses_a_manager_acting_on_another_manager(): void
    {
        $this->expectException(RoleAssignmentForbiddenException::class);

        $this->ask($this->manager, $this->manager->getId()->toRfc4122());
    }

    public function test_it_refuses_an_employee(): void
    {
        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->ask($this->employee, $this->employee->getId()->toRfc4122());
    }

    private function ask(User $actor, string $userId, ?string $organizationId = null): ?MemberView
    {
        return new GetManageableMemberHandler($this->organizations)(new GetManageableMemberQuery(
            $organizationId ?? $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $userId,
        ));
    }
}
