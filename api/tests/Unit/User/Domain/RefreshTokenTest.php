<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Domain;

use App\Tests\Fixtures\UserMother;
use App\User\Domain\Exception\InvalidRefreshTokenException;
use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Model\RefreshTokenSecret;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RefreshToken::class)]
#[CoversClass(RefreshTokenSecret::class)]
final class RefreshTokenTest extends TestCase
{
    private const string SIGN_IN = '2026-11-12 16:00:00';

    public function test_it_stores_only_the_hash_of_the_secret(): void
    {
        $secret = RefreshTokenSecret::generate();

        $token = self::signIn($secret);

        self::assertSame(hash('sha256', $secret->value), $token->getSecretHash());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret->value);
        self::assertNotSame($secret->value, RefreshTokenSecret::generate()->value);
    }

    public function test_it_expires_30_days_after_sign_in(): void
    {
        $token = self::signIn();

        self::assertEquals(self::at('2026-12-12 16:00:00'), $token->getExpiresAt());
        self::assertTrue($token->isUsableAt(self::at('2026-12-12 15:59:59')));
        self::assertFalse($token->isUsableAt(self::at('2026-12-12 16:00:00')));
    }

    public function test_it_gives_the_successor_30_more_days_in_the_same_session(): void
    {
        $token = self::signIn();

        $successor = $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-20 08:00:00'));

        self::assertTrue($successor->getSessionId()->equals($token->getSessionId()));
        self::assertSame($token->getUser(), $successor->getUser());
        self::assertEquals(self::at(self::SIGN_IN), $successor->getSessionStartedAt());
        self::assertEquals(self::at('2026-12-20 08:00:00'), $successor->getExpiresAt());
        self::assertEquals(self::at('2026-11-20 08:00:00'), $token->getRotatedAt());
    }

    public function test_it_caps_every_token_at_90_days_from_sign_in(): void
    {
        $token = self::signIn()
            ->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-12-10 16:00:00'))
            ->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2027-01-07 16:00:00'));

        $successor = $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2027-01-30 16:00:00'));

        self::assertEquals(self::at('2027-02-10 16:00:00'), $successor->getExpiresAt());
    }

    public function test_it_can_be_rotated_again_within_30_seconds(): void
    {
        $token = self::signIn();
        $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-12 17:00:00'));

        $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-12 17:00:30'));

        self::assertEquals(self::at('2026-11-12 17:00:00'), $token->getRotatedAt(), 'The first rotation starts the grace period.');
        self::assertFalse($token->isReusedAt(self::at('2026-11-12 17:00:30')));
    }

    public function test_it_counts_as_reuse_after_the_grace_period(): void
    {
        $token = self::signIn();
        $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-12 17:00:00'));
        $later = self::at('2026-11-12 17:00:31');

        self::assertTrue($token->isReusedAt($later));
        self::assertFalse($token->isUsableAt($later));
        $this->expectException(InvalidRefreshTokenException::class);
        $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), $later);
    }

    public function test_it_is_never_reuse_once_revoked_or_expired(): void
    {
        $revoked = self::signIn();
        $revoked->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-12 17:00:00'));
        $revoked->revoke(self::at('2026-11-12 18:00:00'));
        $expired = self::signIn();
        $expired->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-12 17:00:00'));

        self::assertFalse($revoked->isReusedAt(self::at('2026-11-12 19:00:00')));
        self::assertFalse($expired->isReusedAt(self::at('2026-12-12 16:00:00')));
    }

    public function test_it_refuses_to_rotate_a_revoked_token(): void
    {
        $token = self::signIn();
        $token->revoke(self::at('2026-11-12 18:00:00'));

        $this->expectException(InvalidRefreshTokenException::class);
        $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-11-12 18:00:01'));
    }

    public function test_it_refuses_to_rotate_an_expired_token(): void
    {
        $token = self::signIn();

        $this->expectException(InvalidRefreshTokenException::class);
        $token->rotate(Uuid::v7(), RefreshTokenSecret::generate(), self::at('2026-12-12 16:00:00'));
    }

    public function test_it_keeps_the_first_revocation(): void
    {
        $token = self::signIn();

        $token->revoke(self::at('2026-11-12 18:00:00'));
        $token->revoke(self::at('2026-11-12 19:00:00'));

        self::assertEquals(self::at('2026-11-12 18:00:00'), $token->getRevokedAt());
    }

    private static function signIn(?RefreshTokenSecret $secret = null): RefreshToken
    {
        return RefreshToken::issueAtSignIn(
            Uuid::v7(),
            UserMother::any(),
            Uuid::v7(),
            $secret ?? RefreshTokenSecret::generate(),
            self::at(self::SIGN_IN),
        );
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }
}
