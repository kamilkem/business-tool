<?php

declare(strict_types=1);

namespace App\User\Domain\Exception;

final class EmailAlreadyRegisteredException extends UserException
{
    public static function forEmail(string $email): self
    {
        return new self(\sprintf('A user with the e-mail "%s" already exists.', $email));
    }
}
