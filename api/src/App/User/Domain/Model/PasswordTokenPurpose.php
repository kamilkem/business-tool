<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

/** What a password token was issued for (ADR 0013). Both end with the person setting a password. */
enum PasswordTokenPurpose: string
{
    /** A new member sets their first password. */
    case Invitation = 'invitation';
    /** A person who forgot their password sets a new one (#13). */
    case PasswordReset = 'password_reset';

    /** How long a token stays valid: 7 days for an invitation, 1 hour for a reset. */
    public function lifetime(): \DateInterval
    {
        return match ($this) {
            self::Invitation => new \DateInterval('P7D'),
            self::PasswordReset => new \DateInterval('PT1H'),
        };
    }
}
