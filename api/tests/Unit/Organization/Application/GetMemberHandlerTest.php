<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\ListMembers\MemberView;
use App\Organization\Domain\Exception\OrganizationManagementForbiddenException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetMemberHandler::class)]
final class GetMemberHandlerTest extends TestCase
{
    private InMemoryOrganizationRepository $organizations;
    private User $owner;
    private User $employee;
    private Organization $organization;

    protected function setUp(): void
    {
        $this->organizations = new InMemoryOrganizationRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->organization = OrganizationMother::ownedBy($this->owner);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee);
        $this->organizations->add($this->organization);
    }

    public function test_it_returns_the_member(): void
    {
        $view = $this->ask($this->owner, $this->employee->getId()->toRfc4122());

        self::assertNotNull($view);
        self::assertSame(
            [$this->employee->getId()->toRfc4122(), 'eve@example.com', 'employee', true],
            [$view->userId, $view->email, $view->role, $view->invitationPending],
        );
    }

    public function test_it_returns_null_for_a_user_who_is_not_a_member(): void
    {
        self::assertNull($this->ask($this->owner, Uuid::v7()->toRfc4122()));
    }

    public function test_it_returns_null_for_an_actor_who_is_not_a_member(): void
    {
        self::assertNull($this->ask(UserMother::any('stranger@example.com'), $this->employee->getId()->toRfc4122()));
    }

    public function test_it_refuses_an_employee(): void
    {
        $this->expectException(OrganizationManagementForbiddenException::class);

        $this->ask($this->employee, $this->owner->getId()->toRfc4122());
    }

    private function ask(User $actor, string $userId): ?MemberView
    {
        return (new GetMemberHandler($this->organizations))(new GetMemberQuery(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            $userId,
        ));
    }
}
