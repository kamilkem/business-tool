<?php

declare(strict_types=1);

namespace App\User\Application\Command\RequestPasswordReset;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Link\ClientApp;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Mail\TransactionalMailerInterface;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\User;
use App\User\Domain\Repository\PasswordTokenRepositoryInterface;
use App\User\Domain\Repository\UserRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A reset depends on the global User only: it is sent whatever the person's memberships, also when
 * every one of them is deactivated (ADR 0014), since a password gives no access to an organization by
 * itself. It also works for an invited user who never set a password; their invitation stays valid.
 */
final readonly class RequestPasswordResetHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordTokenRepositoryInterface $tokens,
        private TransactionalMailerInterface $mailer,
        private AppLinks $links,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestPasswordResetCommand $command): void
    {
        $app = ClientApp::from($command->app);
        $user = $this->users->findByEmail(User::normalizeEmail($command->email));
        if (null === $user) {
            return; // Not an error: the response must not tell whether the e-mail has an account.
        }

        $now = $this->clock->now();
        foreach ($this->tokens->findUnusedByUser($user->getId(), PasswordTokenPurpose::PasswordReset) as $previous) {
            $previous->invalidate($now);
        }
        $secret = PasswordTokenSecret::generate();
        $this->tokens->add(new PasswordToken(Uuid::fromString($command->tokenId), $user, PasswordTokenPurpose::PasswordReset, $secret, $now));

        // Queued only after the token is saved (adr/0012): the link in the e-mail always works.
        $lifetime = PasswordTokenPurpose::PasswordReset->lifetime();
        $this->mailer->send(new TransactionalEmail(
            to: $user->getEmail(),
            subject: 'Reset your Business Tool password',
            template: 'email/password_reset.html.twig',
            context: [
                'firstName' => $user->getFirstName(),
                'link' => $this->links->setPassword($app, $secret),
                'validForMinutes' => $lifetime->h * 60 + $lifetime->i,
            ],
        ));
    }
}
