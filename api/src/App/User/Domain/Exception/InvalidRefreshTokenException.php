<?php

declare(strict_types=1);

namespace App\User\Domain\Exception;

/**
 * The refresh token is unknown, expired, revoked, or was reused after its rotation (ADR 0020). One
 * exception and one message for all of them: the client signs the person out either way.
 */
final class InvalidRefreshTokenException extends UserException
{
    public static function create(): self
    {
        return new self('Your session has expired. Sign in again.');
    }
}
