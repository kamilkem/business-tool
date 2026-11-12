<?php

declare(strict_types=1);

namespace App\User\Application\Command\ChangePassword;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ChangePasswordHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private UserPasswordHasherInterface $passwordHasher,
        private PasswordTokenRepositoryInterface $passwordTokens,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private TransactionalMailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    /** @throws UserNotFoundException */
    public function __invoke(ChangePasswordCommand $command): void
    {
        $user = $this->users->findById(Uuid::fromString($command->userId))
            ?? throw UserNotFoundException::forId($command->userId);

        $now = $this->clock->now();
        $user->changePassword($this->passwordHasher->hashPassword($user, $command->newPassword));
        $this->users->save($user);

        // Whatever route set the password, older links must stop working (ADR 0013).
        foreach ($this->passwordTokens->findUnusedByUser($user->getId()) as $link) {
            $link->invalidate($now);
            $this->passwordTokens->save($link);
        }

        // A new password ends every session, on every device and in both apps (ADR 0020).
        foreach ($this->refreshTokens->findUsableByUser($user->getId(), $now) as $refreshToken) {
            $refreshToken->revoke($now);
            $this->refreshTokens->save($refreshToken);
        }

        // Queued only after the password is saved (adr/0012). It tells the owner of the address if
        // someone else changed it, e.g. on a device left signed in.
        $this->mailer->send(new TransactionalEmail(
            to: $user->getEmail(),
            subject: 'Your Business Tool password was changed',
            template: 'email/password_changed.html.twig',
            context: ['firstName' => $user->getFirstName()],
        ));
    }
}
