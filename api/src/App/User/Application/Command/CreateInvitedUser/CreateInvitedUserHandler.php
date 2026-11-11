<?php

declare(strict_types=1);

namespace App\User\Application\Command\CreateInvitedUser;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\User\Domain\Exception\EmailAlreadyRegisteredException;
use App\User\Domain\Model\User;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateInvitedUserHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
    ) {
    }

    /** @throws EmailAlreadyRegisteredException */
    public function __invoke(CreateInvitedUserCommand $command): void
    {
        if (null !== $this->users->findByEmail($command->email)) {
            throw EmailAlreadyRegisteredException::forEmail(User::normalizeEmail($command->email));
        }

        $this->users->add(new User(
            Uuid::fromString($command->userId),
            $command->email,
            $this->clock->now(),
            trim($command->firstName),
            trim($command->lastName),
        ));
    }
}
