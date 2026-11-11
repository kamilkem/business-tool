<?php

declare(strict_types=1);

namespace App\User\Domain\Exception;

/** An invitation is only for a user who has not set a password yet; the others sign in. */
final class PasswordAlreadySetException extends UserException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" has already set a password.', $userId));
    }
}
