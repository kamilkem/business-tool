<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Tests\Fixtures\UserMother;
use App\User\Application\Command\SetPassword\SetPasswordCommand;
use App\User\Application\Command\SetPassword\SetPasswordHandler;
use App\User\Domain\Exception\InvalidPasswordTokenException;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SetPasswordHandler::class)]
final class SetPasswordHandlerTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    private InMemoryPasswordTokenRepository $tokens;
    private InMemoryUserRepository $users;
    private User $user;

    protected function setUp(): void
    {
        $this->tokens = new InMemoryPasswordTokenRepository();
        $this->users = new InMemoryUserRepository();
        $this->user = UserMother::any('eve@example.com');
        $this->users->add($this->user);
    }

    public function test_it_sets_the_hashed_password_and_uses_the_token(): void
    {
        [$token, $secret] = $this->issue(PasswordTokenPurpose::Invitation, '2026-11-10 12:00:00');

        $this->handle($secret, 'correct horse battery');

        self::assertSame('hashed:correct horse battery', $this->user->getPassword());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $token->getUsedAt());
        self::assertGreaterThan(0, $this->tokens->saveCallCount);
    }

    public function test_it_invalidates_every_other_unused_token_of_the_user(): void
    {
        [, $secret] = $this->issue(PasswordTokenPurpose::Invitation, '2026-11-10 12:00:00');
        [$reset] = $this->issue(PasswordTokenPurpose::PasswordReset, '2026-11-11 11:30:00');
        $otherUsers = new PasswordToken(Uuid::v7(), UserMother::any('max@example.com'), PasswordTokenPurpose::Invitation, PasswordTokenSecret::generate(), new \DateTimeImmutable('2026-11-10 12:00:00'));
        $this->tokens->add($otherUsers);

        $this->handle($secret, 'correct horse battery');

        self::assertEquals(new \DateTimeImmutable(self::NOW), $reset->getInvalidatedAt());
        self::assertNull($otherUsers->getInvalidatedAt());
        self::assertSame([], $this->tokens->findUnusedByUser($this->user->getId()));
    }

    public function test_it_refuses_an_unknown_token(): void
    {
        $this->issue(PasswordTokenPurpose::Invitation, '2026-11-10 12:00:00');

        $this->expectRefusal(PasswordTokenSecret::generate()->value);
    }

    public function test_it_refuses_a_used_token(): void
    {
        [$token, $secret] = $this->issue(PasswordTokenPurpose::Invitation, '2026-11-10 12:00:00');
        $token->use(new \DateTimeImmutable('2026-11-10 13:00:00'));

        $this->expectRefusal($secret);
    }

    public function test_it_refuses_an_expired_token(): void
    {
        [, $secret] = $this->issue(PasswordTokenPurpose::PasswordReset, '2026-11-11 10:59:59');

        $this->expectRefusal($secret);
    }

    public function test_it_refuses_an_invalidated_token(): void
    {
        [$token, $secret] = $this->issue(PasswordTokenPurpose::Invitation, '2026-11-10 12:00:00');
        $token->invalidate(new \DateTimeImmutable('2026-11-10 13:00:00'));

        $this->expectRefusal($secret);
    }

    /** @return array{PasswordToken, string} */
    private function issue(PasswordTokenPurpose $purpose, string $issuedAt): array
    {
        $secret = PasswordTokenSecret::generate();
        $token = new PasswordToken(Uuid::v7(), $this->user, $purpose, $secret, new \DateTimeImmutable($issuedAt));
        $this->tokens->add($token);

        return [$token, $secret->value];
    }

    private function expectRefusal(string $secret): void
    {
        try {
            $this->handle($secret, 'correct horse battery');
            self::fail('Expected InvalidPasswordTokenException.');
        } catch (InvalidPasswordTokenException $e) {
            self::assertSame('This link is invalid or has expired.', $e->getMessage());
        }

        self::assertFalse($this->user->hasPassword());
    }

    private function handle(string $secret, string $password): void
    {
        new SetPasswordHandler($this->tokens, $this->users, new PrefixingPasswordHasher(), new MockClock(self::NOW))(
            new SetPasswordCommand($secret, $password),
        );
    }
}

/** Marks the password as hashed, so the test sees the hasher was used. */
final class PrefixingPasswordHasher implements UserPasswordHasherInterface
{
    public function hashPassword(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): string
    {
        return 'hashed:'.$plainPassword;
    }

    public function isPasswordValid(PasswordAuthenticatedUserInterface $user, #[\SensitiveParameter] string $plainPassword): bool
    {
        return $user->getPassword() === 'hashed:'.$plainPassword;
    }

    public function needsRehash(PasswordAuthenticatedUserInterface $user): bool
    {
        return false;
    }
}
