<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Shared\Domain\Language\Language;
use App\Tests\Fixtures\UserMother;
use App\User\Application\Command\ChangeLanguage\ChangeLanguageCommand;
use App\User\Application\Command\ChangeLanguage\ChangeLanguageHandler;
use App\User\Domain\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ChangeLanguageHandler::class)]
final class ChangeLanguageHandlerTest extends TestCase
{
    public function test_it_changes_the_users_language(): void
    {
        $users = new InMemoryUserRepository();
        $user = UserMother::any();
        $users->add($user);

        (new ChangeLanguageHandler($users))(new ChangeLanguageCommand($user->getId()->toRfc4122(), 'pl'));

        self::assertSame(Language::Polish, $users->findById($user->getId())?->getLanguage());
    }

    public function test_it_clears_the_language(): void
    {
        $users = new InMemoryUserRepository();
        $user = UserMother::any();
        $user->changeLanguage(Language::Polish);
        $users->add($user);

        (new ChangeLanguageHandler($users))(new ChangeLanguageCommand($user->getId()->toRfc4122(), null));

        self::assertNull($users->findById($user->getId())?->getLanguage());
    }

    public function test_it_refuses_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        (new ChangeLanguageHandler(new InMemoryUserRepository()))(new ChangeLanguageCommand(Uuid::v7()->toRfc4122(), 'pl'));
    }
}
