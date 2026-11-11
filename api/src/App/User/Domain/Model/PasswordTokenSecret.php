<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

/**
 * The secret part of a password link (ADR 0013): 32 random bytes, base64url-encoded. It is sent in
 * the e-mail only; the database keeps its SHA-256 hash. A fast hash is enough because the secret
 * is random, not chosen by a person.
 */
final readonly class PasswordTokenSecret
{
    private const int BYTES = 32;

    private function __construct(
        public string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }

    public function hash(): string
    {
        return self::hashOf($this->value);
    }

    /** The hash a token is stored and looked up by, for a secret as typed into a request. */
    public static function hashOf(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
