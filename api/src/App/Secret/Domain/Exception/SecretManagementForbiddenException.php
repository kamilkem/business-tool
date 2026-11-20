<?php

declare(strict_types=1);

namespace App\Secret\Domain\Exception;

/** Only owners set or remove an organization's secrets (ADR 0026 §5). */
final class SecretManagementForbiddenException extends SecretException
{
    public static function forUser(string $userId): self
    {
        return new self(\sprintf('User "%s" may not set or remove secrets: only owners do.', $userId));
    }
}
