<?php

declare(strict_types=1);

namespace App\User\Domain\Exception;

/**
 * The password link is unknown, used, invalidated or expired. One exception and one message for all
 * of them (ADR 0013), so the response tells nothing about which it was.
 */
final class InvalidPasswordTokenException extends UserException
{
    public static function create(): self
    {
        return new self('This link is invalid or has expired.');
    }
}
