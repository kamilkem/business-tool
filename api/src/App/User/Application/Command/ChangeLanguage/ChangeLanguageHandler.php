<?php

declare(strict_types=1);

namespace App\User\Application\Command\ChangeLanguage;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Language\Language;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeLanguageHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
    ) {
    }

    /** @throws UserNotFoundException */
    public function __invoke(ChangeLanguageCommand $command): void
    {
        $user = $this->users->findById(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forId($command->userId);

        $user->changeLanguage(null === $command->language ? null : Language::from($command->language));
        $this->users->save($user);
    }
}
