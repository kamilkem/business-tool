<?php

declare(strict_types=1);

namespace App\User\Application\Command\SetPassword;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\User\Domain\Exception\InvalidPasswordTokenException;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use App\User\Domain\Repository\RefreshTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class SetPasswordHandler implements CommandHandlerInterface
{
    public function __construct(
        private PasswordTokenRepositoryInterface $tokens,
        private UserRepositoryInterface $users,
        private UserPasswordHasherInterface $passwordHasher,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private ClockInterface $clock,
    ) {
    }

    /** @throws InvalidPasswordTokenException when the token is unknown, used, invalidated or expired */
    public function __invoke(SetPasswordCommand $command): void
    {
        $token = $this->tokens->findBySecretHash(PasswordTokenSecret::hashOf($command->token))
            ?? throw InvalidPasswordTokenException::create();

        $now = $this->clock->now();
        $token->use($now);

        $user = $token->getUser();
        $user->changePassword($this->passwordHasher->hashPassword($user, $command->plainPassword));
        // Whatever route set the password, older links must stop working (ADR 0013).
        foreach ($this->tokens->findUnusedByUser($user->getId()) as $other) {
            $other->invalidate($now);
        }

        $this->tokens->save($token);
        $this->users->save($user);

        // A new password ends every session, on every device and in both apps (ADR 0020).
        foreach ($this->refreshTokens->findUsableByUser($user->getId(), $now) as $refreshToken) {
            $refreshToken->revoke($now);
            $this->refreshTokens->save($refreshToken);
        }
    }
}
