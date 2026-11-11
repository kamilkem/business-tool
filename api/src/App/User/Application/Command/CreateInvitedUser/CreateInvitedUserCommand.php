<?php

declare(strict_types=1);

namespace App\User\Application\Command\CreateInvitedUser;

use App\Shared\Application\Command\CommandInterface;

/**
 * Creates a user without a password, for a person being added to an organization who has no
 * account yet. They set a password through the invitation link (ADR 0013).
 */
final readonly class CreateInvitedUserCommand implements CommandInterface
{
    public function __construct(
        public string $userId,
        public string $email,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
