<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Domain;

use App\Tests\Fixtures\UserMother;
use App\User\Domain\Exception\InvalidPasswordTokenException;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PasswordToken::class)]
#[CoversClass(PasswordTokenPurpose::class)]
#[CoversClass(PasswordTokenSecret::class)]
final class PasswordTokenTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    public function test_it_generates_a_random_url_safe_secret_of_32_bytes(): void
    {
        $secret = PasswordTokenSecret::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $secret->value);
        self::assertNotSame($secret->value, PasswordTokenSecret::generate()->value);
    }

    public function test_it_stores_only_the_sha256_hash_of_the_secret(): void
    {
        $secret = PasswordTokenSecret::generate();

        $token = $this->token(PasswordTokenPurpose::Invitation, $secret);

        self::assertSame(hash('sha256', $secret->value), $token->getSecretHash());
        self::assertSame($token->getSecretHash(), PasswordTokenSecret::hashOf($secret->value));
    }

    public function test_it_keeps_an_invitation_valid_for_7_days(): void
    {
        $token = $this->token(PasswordTokenPurpose::Invitation);

        self::assertEquals(new \DateTimeImmutable('2026-11-18 12:00:00'), $token->getExpiresAt());
        self::assertTrue($token->isValidAt(new \DateTimeImmutable('2026-11-18 11:59:59')));
        self::assertFalse($token->isValidAt(new \DateTimeImmutable('2026-11-18 12:00:00')));
    }

    public function test_it_keeps_a_password_reset_valid_for_1_hour(): void
    {
        $token = $this->token(PasswordTokenPurpose::PasswordReset);

        self::assertEquals(new \DateTimeImmutable('2026-11-11 13:00:00'), $token->getExpiresAt());
        self::assertTrue($token->isValidAt(new \DateTimeImmutable('2026-11-11 12:59:59')));
        self::assertFalse($token->isValidAt(new \DateTimeImmutable('2026-11-11 13:00:00')));
    }

    public function test_it_can_be_used_once(): void
    {
        $token = $this->token(PasswordTokenPurpose::Invitation);
        $usedAt = new \DateTimeImmutable('2026-11-12 08:00:00');

        $token->use($usedAt);

        self::assertEquals($usedAt, $token->getUsedAt());
        self::assertFalse($token->isValidAt($usedAt));
        $this->expectException(InvalidPasswordTokenException::class);
        $token->use($usedAt);
    }

    public function test_it_cannot_be_used_after_it_expired(): void
    {
        $token = $this->token(PasswordTokenPurpose::Invitation);

        $this->expectException(InvalidPasswordTokenException::class);

        $token->use(new \DateTimeImmutable('2026-11-19 12:00:00'));
    }

    public function test_it_cannot_be_used_after_it_was_invalidated(): void
    {
        $token = $this->token(PasswordTokenPurpose::Invitation);
        $token->invalidate(new \DateTimeImmutable('2026-11-12 08:00:00'));

        self::assertFalse($token->isValidAt(new \DateTimeImmutable('2026-11-12 09:00:00')));
        $this->expectException(InvalidPasswordTokenException::class);
        $token->use(new \DateTimeImmutable('2026-11-12 09:00:00'));
    }

    public function test_it_keeps_the_history_of_a_used_token_when_invalidated(): void
    {
        $token = $this->token(PasswordTokenPurpose::Invitation);
        $token->use(new \DateTimeImmutable('2026-11-12 08:00:00'));

        $token->invalidate(new \DateTimeImmutable('2026-11-12 09:00:00'));

        self::assertNull($token->getInvalidatedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 08:00:00'), $token->getUsedAt());
    }

    private function token(PasswordTokenPurpose $purpose, ?PasswordTokenSecret $secret = null): PasswordToken
    {
        return new PasswordToken(
            Uuid::v7(),
            UserMother::any(),
            $purpose,
            $secret ?? PasswordTokenSecret::generate(),
            new \DateTimeImmutable(self::NOW),
        );
    }
}
