<?php

declare(strict_types=1);

namespace App\User\Domain\Exception;

final class UserNotFoundException extends UserException
{
    public static function forId(string $id): self
    {
        return new self(\sprintf('User "%s" not found.', $id));
    }
}
