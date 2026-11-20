<?php

declare(strict_types=1);

namespace App\Secret\Domain\Exception;

/** The secret is not one of the selected organization's. */
final class OrganizationSecretNotFoundException extends SecretException
{
    public static function withId(string $secretId): self
    {
        return new self(\sprintf('Secret "%s" not found.', $secretId));
    }
}
