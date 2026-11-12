<?php

declare(strict_types=1);

namespace App\Ksef\Domain\Model;

/**
 * An authenticated KSeF session for one context (the organization's NIP): the short-lived access
 * token and the refresh token. It lives in memory for one sync run and is never stored or serialized
 * (ADR 0023 §2). The client replaces the access token when it refreshes it.
 */
final class KsefSession
{
    public function __construct(
        public readonly string $contextNip,
        public readonly string $referenceNumber,
        #[\SensitiveParameter] private string $accessToken,
        private \DateTimeImmutable $accessTokenValidUntil,
        #[\SensitiveParameter] private readonly string $refreshToken,
        public readonly \DateTimeImmutable $refreshTokenValidUntil,
    ) {
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function accessTokenValidUntil(): \DateTimeImmutable
    {
        return $this->accessTokenValidUntil;
    }

    public function refreshToken(): string
    {
        return $this->refreshToken;
    }

    public function replaceAccessToken(#[\SensitiveParameter] string $accessToken, \DateTimeImmutable $validUntil): void
    {
        $this->accessToken = $accessToken;
        $this->accessTokenValidUntil = $validUntil;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'contextNip' => $this->contextNip,
            'referenceNumber' => $this->referenceNumber,
            'accessTokenValidUntil' => $this->accessTokenValidUntil->format(\DATE_ATOM),
            'refreshTokenValidUntil' => $this->refreshTokenValidUntil->format(\DATE_ATOM),
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A KSeF session holds tokens and is never serialized (ADR 0023 §2).');
    }
}
