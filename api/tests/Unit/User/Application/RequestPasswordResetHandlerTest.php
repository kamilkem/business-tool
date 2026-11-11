<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Shared\Application\Mail\TransactionalEmail;
use App\Tests\Unit\Shared\Application\RecordingMailer;
use App\User\Application\Command\RequestPasswordReset\RequestPasswordResetCommand;
use App\User\Application\Command\RequestPasswordReset\RequestPasswordResetHandler;
use App\User\Application\Link\AppLinks;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RequestPasswordResetHandler::class)]
final class RequestPasswordResetHandlerTest extends TestCase
{
    private const string NOW = '2026-11-11 12:00:00';

    private InMemoryUserRepository $users;
    private InMemoryPasswordTokenRepository $tokens;
    private RecordingMailer $mailer;
    private User $user;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->tokens = new InMemoryPasswordTokenRepository();
        $this->mailer = new RecordingMailer();
        $this->user = new User(Uuid::v7(), 'max@example.com', 'Max', 'Kowalski');
        $this->user->changePassword('hash');
        $this->users->add($this->user);
    }

    public function test_it_issues_a_one_hour_reset_token_and_queues_the_email_with_its_link(): void
    {
        $tokenId = Uuid::v7()->toRfc4122();

        $this->handle('max@example.com', 'admin', $tokenId);

        $tokens = $this->tokens->all();
        self::assertCount(1, $tokens);
        self::assertSame($tokenId, $tokens[0]->getId()->toRfc4122());
        self::assertSame($this->user, $tokens[0]->getUser());
        self::assertSame(PasswordTokenPurpose::PasswordReset, $tokens[0]->getPurpose());
        self::assertEquals(new \DateTimeImmutable('2026-11-11 13:00:00'), $tokens[0]->getExpiresAt());

        $email = $this->onlyEmail();
        self::assertSame('max@example.com', $email->to);
        self::assertSame('email/password_reset.html.twig', $email->template);
        self::assertSame('Max', $email->context['firstName']);
        self::assertSame(60, $email->context['validForMinutes']);
        self::assertMatchesRegularExpression('~^https://admin\.example/set-password#token=[A-Za-z0-9_-]{43}$~', (string) $email->context['link']);
        $secret = explode('#token=', (string) $email->context['link'])[1];
        self::assertSame($tokens[0]->getSecretHash(), PasswordTokenSecret::hashOf($secret));
    }

    /** @return iterable<string, array{string, non-empty-string}> */
    public static function linkByApp(): iterable
    {
        yield 'admin panel' => ['admin', 'https://admin.example/set-password#token='];
        yield 'time clock' => ['timeclock', 'https://clock.example/set-password#token='];
    }

    /** @param non-empty-string $linkPrefix */
    #[DataProvider('linkByApp')]
    public function test_it_links_to_the_requested_app(string $app, string $linkPrefix): void
    {
        $this->handle('max@example.com', $app);

        self::assertStringStartsWith($linkPrefix, (string) $this->onlyEmail()->context['link']);
    }

    public function test_it_finds_the_user_whatever_the_case_and_surrounding_spaces_of_the_email(): void
    {
        $this->handle('  Max@Example.COM ', 'admin');

        self::assertSame('max@example.com', $this->onlyEmail()->to);
    }

    public function test_it_does_nothing_for_an_unknown_email(): void
    {
        $this->handle('nobody@example.com', 'admin');

        self::assertSame([], $this->tokens->all());
        self::assertSame([], $this->mailer->emails);
    }

    public function test_it_invalidates_the_previous_reset_but_not_an_invitation(): void
    {
        $invited = new User(Uuid::v7(), 'eve@example.com', 'Eve');
        $this->users->add($invited);
        $invitation = new PasswordToken(Uuid::v7(), $invited, PasswordTokenPurpose::Invitation, PasswordTokenSecret::generate(), new \DateTimeImmutable('2026-11-10 12:00:00'));
        $previous = new PasswordToken(Uuid::v7(), $invited, PasswordTokenPurpose::PasswordReset, PasswordTokenSecret::generate(), new \DateTimeImmutable('2026-11-11 11:50:00'));
        $this->tokens->add($invitation);
        $this->tokens->add($previous);

        $this->handle('eve@example.com', 'timeclock');

        self::assertEquals(new \DateTimeImmutable(self::NOW), $previous->getInvalidatedAt());
        self::assertNull($invitation->getInvalidatedAt());
        self::assertCount(1, $this->tokens->findUnusedByUser($invited->getId(), PasswordTokenPurpose::PasswordReset));
        self::assertSame('eve@example.com', $this->onlyEmail()->to);
    }

    private function handle(string $email, string $app, ?string $tokenId = null): void
    {
        new RequestPasswordResetHandler(
            $this->users,
            $this->tokens,
            $this->mailer,
            new AppLinks('https://admin.example/', 'https://clock.example'),
            new MockClock(self::NOW),
        )(new RequestPasswordResetCommand($tokenId ?? Uuid::v7()->toRfc4122(), $email, $app));
    }

    private function onlyEmail(): TransactionalEmail
    {
        self::assertCount(1, $this->mailer->emails);

        return $this->mailer->emails[0];
    }
}
