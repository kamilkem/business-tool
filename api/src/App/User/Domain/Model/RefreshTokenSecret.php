<?php

declare(strict_types=1);

namespace App\User\Domain\Model;

/**
 * The secret of a refresh token (ADR 0020): 32 random bytes, base64url-encoded, as for password
 * links (ADR 0013). It travels only in the HttpOnly cookie; the database keeps its SHA-256 hash. A
 * fast hash is enough because the secret is random, not chosen by a person.
 */
final readonly class RefreshTokenSecret
{
    private const int BYTES = 32;

    private function __construct(
        #[\SensitiveParameter]
        public string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }

    /** A secret generated earlier (by the Ui layer, which needs it for the cookie) or read from a cookie. */
    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        return new self($value);
    }

    public function hash(): string
    {
        return self::hashOf($this->value);
    }

    /** The hash a token is stored and looked up by. */
    public static function hashOf(#[\SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }
}
