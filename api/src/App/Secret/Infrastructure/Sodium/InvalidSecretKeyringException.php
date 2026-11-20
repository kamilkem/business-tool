<?php

declare(strict_types=1);

namespace App\Secret\Infrastructure\Sodium;

/**
 * SECRETS_ENCRYPTION_KEYS is missing or malformed: a deployment error, never a fallback (ADR 0026 §3).
 * The message names the entry's position or key id, never a key.
 */
final class InvalidSecretKeyringException extends \RuntimeException
{
    public static function empty(): self
    {
        return new self('SECRETS_ENCRYPTION_KEYS is empty: set "<keyId>:<base64 32-byte key>" entries (bin/console app:secret:generate-key prints one).');
    }

    public static function malformedEntry(int $position): self
    {
        return new self(\sprintf('Entry %d of SECRETS_ENCRYPTION_KEYS is not "<keyId>:<base64 key>" with a key id of letters, digits, ".", "_" or "-" (at most 64).', $position));
    }

    public static function invalidKey(string $keyId, int $expectedBytes): self
    {
        return new self(\sprintf('Key "%s" of SECRETS_ENCRYPTION_KEYS is not base64 of %d bytes.', $keyId, $expectedBytes));
    }

    public static function duplicateKeyId(string $keyId): self
    {
        return new self(\sprintf('Key id "%s" appears more than once in SECRETS_ENCRYPTION_KEYS.', $keyId));
    }
}
