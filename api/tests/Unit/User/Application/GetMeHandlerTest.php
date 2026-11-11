<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryMembershipRepository;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\User\Application\Query\GetMe\GetMeHandler;
use App\User\Application\Query\GetMe\GetMeQuery;
use App\User\Domain\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(GetMeHandler::class)]
final class GetMeHandlerTest extends TestCase
{
    public function test_it_returns_the_user_with_their_memberships(): void
    {
        $users = new InMemoryUserRepository();
        $organizations = new InMemoryOrganizationRepository();
        $user = UserMother::any();
        $users->add($user);
        $organizations->add(OrganizationMother::ownedBy($user, 'Corner Pub'));
        $other = OrganizationMother::ownedBy(UserMother::any('boss@example.com'), 'Big Bar');
        OrganizationMother::addMember($other, $user, MembershipRole::Employee);
        $organizations->add($other);

        $view = (new GetMeHandler($users, new InMemoryMembershipRepository($organizations)))(
            new GetMeQuery($user->getId()->toRfc4122()),
        );

        self::assertSame('jane@example.com', $view->email);
        self::assertSame(
            [['Big Bar', 'employee', 'Europe/Warsaw'], ['Corner Pub', 'owner', 'Europe/Warsaw']],
            array_map(static fn ($m): array => [$m->organizationName, $m->role, $m->organizationTimezone], $view->memberships),
        );
    }

    public function test_it_refuses_an_unknown_user(): void
    {
        $handler = new GetMeHandler(new InMemoryUserRepository(), new InMemoryMembershipRepository(new InMemoryOrganizationRepository()));

        $this->expectException(UserNotFoundException::class);

        $handler(new GetMeQuery(Uuid::v7()->toRfc4122()));
    }
}
