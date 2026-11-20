<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/** Whether the organization's KSeF import may run (ADR 0023 §3, §5). */
enum KsefConnectionStatus: string
{
    /** A token is saved and was not rejected since; the sync runs. */
    case Active = 'active';

    /** KSeF refused the token, or the stored token no longer decrypts; the owner saves a new one. */
    case CredentialsRejected = 'credentials_rejected';

    /** No token: it was removed. Saving one makes the connection active again. */
    case Disabled = 'disabled';
}
