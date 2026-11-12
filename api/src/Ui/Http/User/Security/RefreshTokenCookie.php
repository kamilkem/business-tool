<?php

declare(strict_types=1);

namespace Ui\Http\User\Security;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The refresh token's cookie (ADR 0020): HttpOnly so scripts never read it, Secure, SameSite=Strict
 * so cross-site requests never carry it, and sent only to /api/auth (refresh and sign-out). The API is
 * served under each app's own origin, so each app has its own cookie and session.
 */
final class RefreshTokenCookie
{
    public const string NAME = 'refresh_token';
    public const string PATH = '/api/auth';

    public static function fromRequest(Request $request): ?string
    {
        $value = $request->cookies->get(self::NAME);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    public static function set(Response $response, #[\SensitiveParameter] string $secret, \DateTimeImmutable $expiresAt): void
    {
        $response->headers->setCookie(Cookie::create(
            self::NAME,
            $secret,
            $expiresAt,
            self::PATH,
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_STRICT,
        ));
    }

    public static function clear(Response $response): void
    {
        $response->headers->clearCookie(self::NAME, self::PATH, null, true, true, Cookie::SAMESITE_STRICT);
    }
}
