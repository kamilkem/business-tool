<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Shared\Domain\Language\Language;
use App\Tests\Fixtures\UserMother;
use App\User\Application\Command\CreateInvitedUser\CreateInvitedUserCommand;
use App\User\Application\Command\CreateInvitedUser\CreateInvitedUserHandler;
use App\User\Domain\Exception\EmailAlreadyRegisteredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CreateInvitedUserHandler::class)]
final class CreateInvitedUserHandlerTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    public function test_it_creates_a_user_without_a_password(): void
    {
        $users = new InMemoryUserRepository();
        $id = Uuid::v7();

        (new CreateInvitedUserHandler($users, new MockClock(self::NOW)))(new CreateInvitedUserCommand($id->toRfc4122(), ' Eve@Example.com', ' Eve ', 'Nowak '));

        $user = $users->findById($id);
        self::assertNotNull($user);
        self::assertSame(['eve@example.com', 'Eve', 'Nowak'], [$user->getEmail(), $user->getFirstName(), $user->getLastName()]);
        self::assertFalse($user->hasPassword());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $user->getCreatedAt());
        self::assertNull($user->getLanguage());
    }

    public function test_it_gives_the_user_the_language_the_inviter_chose(): void
    {
        $users = new InMemoryUserRepository();
        $id = Uuid::v7();

        (new CreateInvitedUserHandler($users, new MockClock(self::NOW)))(new CreateInvitedUserCommand($id->toRfc4122(), 'eve@example.com', 'Eve', 'Nowak', 'pl'));

        self::assertSame(Language::Polish, $users->findById($id)?->getLanguage());
    }

    public function test_it_refuses_an_email_that_already_has_a_user(): void
    {
        $users = new InMemoryUserRepository();
        $users->add(UserMother::any('eve@example.com'));

        $this->expectException(EmailAlreadyRegisteredException::class);

        (new CreateInvitedUserHandler($users, new MockClock(self::NOW)))(new CreateInvitedUserCommand(Uuid::v7()->toRfc4122(), 'EVE@example.com', 'Eve', 'Nowak'));
    }
}
