<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Shared\Application\RecordingMailer;
use App\User\Application\Command\ChangePassword\ChangePasswordCommand;
use App\User\Application\Command\ChangePassword\ChangePasswordHandler;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ChangePasswordHandler::class)]
final class ChangePasswordHandlerTest extends TestCase
{
    private const string NOW = '2026-11-12 12:00:00';

    private InMemoryUserRepository $users;
    private InMemoryPasswordTokenRepository $passwordTokens;
    private InMemoryRefreshTokenRepository $refreshTokens;
    private RecordingMailer $mailer;
    private User $user;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->passwordTokens = new InMemoryPasswordTokenRepository();
        $this->refreshTokens = new InMemoryRefreshTokenRepository();
        $this->mailer = new RecordingMailer();
        $this->user = UserMother::any('eve@example.com', new \DateTimeImmutable('2026-11-01 09:00:00'));
        $this->user->changePassword('hashed:old password');
        $this->users->add($this->user);
    }

    public function test_it_stores_the_new_password_hashed(): void
    {
        $this->handle($this->user->getId()->toRfc4122(), 'correct horse battery');

        self::assertSame('hashed:correct horse battery', $this->user->getPassword());
    }

    public function test_it_ends_every_session_of_the_user_and_only_theirs(): void
    {
        $phone = $this->signIn($this->user);
        $laptop = $this->signIn($this->user);
        $someoneElse = $this->signIn(UserMother::any('max@example.com'));

        $this->handle($this->user->getId()->toRfc4122(), 'correct horse battery');

        self::assertEquals(new \DateTimeImmutable(self::NOW), $phone->getRevokedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $laptop->getRevokedAt());
        self::assertNull($someoneElse->getRevokedAt());
    }

    public function test_it_invalidates_unused_set_password_links_of_the_user(): void
    {
        $reset = new PasswordToken(Uuid::v7(), $this->user, PasswordTokenPurpose::PasswordReset, PasswordTokenSecret::generate(), new \DateTimeImmutable('2026-11-12 11:30:00'));
        $this->passwordTokens->add($reset);

        $this->handle($this->user->getId()->toRfc4122(), 'correct horse battery');

        self::assertEquals(new \DateTimeImmutable(self::NOW), $reset->getInvalidatedAt());
    }

    public function test_it_queues_a_password_changed_email_to_the_user(): void
    {
        $this->handle($this->user->getId()->toRfc4122(), 'correct horse battery');

        self::assertCount(1, $this->mailer->emails);
        $email = $this->mailer->emails[0];
        self::assertSame('eve@example.com', $email->to);
        self::assertSame('Your Business Tool password was changed', $email->subject);
        self::assertSame('email/password_changed.html.twig', $email->template);
        self::assertSame(['firstName' => 'Jane'], $email->context);
    }

    public function test_it_refuses_an_unknown_user_and_changes_nothing(): void
    {
        $session = $this->signIn($this->user);

        try {
            $this->handle(Uuid::v7()->toRfc4122(), 'correct horse battery');
            self::fail('Expected UserNotFoundException.');
        } catch (UserNotFoundException) {
        }

        self::assertSame('hashed:old password', $this->user->getPassword());
        self::assertNull($session->getRevokedAt());
        self::assertSame([], $this->mailer->emails);
    }

    private function signIn(User $user): RefreshToken
    {
        $token = RefreshToken::issueAtSignIn(Uuid::v7(), $user, Uuid::v7(), RefreshTokenSecret::generate(), new \DateTimeImmutable('2026-11-12 08:00:00'));
        $this->refreshTokens->add($token);

        return $token;
    }

    private function handle(string $userId, string $newPassword): void
    {
        new ChangePasswordHandler($this->users, new PrefixingPasswordHasher(), $this->passwordTokens, $this->refreshTokens, $this->mailer, new MockClock(self::NOW))(
            new ChangePasswordCommand($userId, $newPassword),
        );
    }
}
