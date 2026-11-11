<?php

declare(strict_types=1);

namespace App\User\Application\Command\RequestPasswordReset;

use App\Shared\Application\Command\CommandInterface;

/**
 * Issues a password reset token for the user with this e-mail and e-mails them the link
 * (ADR 0013, #13). Does nothing for an unknown e-mail, so the caller cannot tell whether an account
 * exists.
 */
final readonly class RequestPasswordResetCommand implements CommandInterface
{
    public function __construct(
        /** Used only if the e-mail belongs to a user. */
        public string $tokenId,
        /** As typed; compared the way User stores e-mails. */
        public string $email,
        /** The app (ClientApp value) whose set-password page the link opens. */
        public string $app,
    ) {
    }
}
