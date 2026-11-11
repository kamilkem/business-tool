<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Tests\Fixtures\UserMother;
use App\User\Application\Query\GetUserByEmail\GetUserByEmailHandler;
use App\User\Application\Query\GetUserByEmail\GetUserByEmailQuery;
use App\User\Application\Query\GetUserByEmail\UserView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetUserByEmailHandler::class)]
#[CoversClass(UserView::class)]
final class GetUserByEmailHandlerTest extends TestCase
{
    public function test_it_finds_the_user_whatever_the_case_of_the_email(): void
    {
        $users = new InMemoryUserRepository();
        $user = UserMother::any('eve@example.com');
        $users->add($user);

        $view = (new GetUserByEmailHandler($users))(new GetUserByEmailQuery(' Eve@Example.com '));

        self::assertEquals(new UserView($user->getId()->toRfc4122(), 'eve@example.com', 'Jane', 'Doe'), $view);
    }

    public function test_it_returns_null_for_an_unknown_email(): void
    {
        self::assertNull((new GetUserByEmailHandler(new InMemoryUserRepository()))(new GetUserByEmailQuery('nobody@example.com')));
    }
}
