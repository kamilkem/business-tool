<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Tests\Fixtures\UserMother;
use App\User\Application\Command\IssueRefreshToken\IssueRefreshTokenCommand;
use App\User\Application\Command\IssueRefreshToken\IssueRefreshTokenHandler;
use App\User\Application\Command\RevokeSession\RevokeSessionCommand;
use App\User\Application\Command\RevokeSession\RevokeSessionHandler;
use App\User\Application\Command\RotateRefreshToken\RotateRefreshTokenCommand;
use App\User\Application\Command\RotateRefreshToken\RotateRefreshTokenHandler;
use App\User\Application\Query\GetRefreshToken\GetRefreshTokenHandler;
use App\User\Application\Query\GetRefreshToken\GetRefreshTokenQuery;
use App\User\Domain\Exception\InvalidRefreshTokenException;
use App\User\Domain\Exception\UserNotFoundException;
use App\User\Domain\Model\RefreshToken;
use App\User\Domain\Model\RefreshTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IssueRefreshTokenHandler::class)]
#[CoversClass(RotateRefreshTokenHandler::class)]
#[CoversClass(RevokeSessionHandler::class)]
#[CoversClass(GetRefreshTokenHandler::class)]
final class RefreshTokenHandlersTest extends TestCase
{
    private const string SIGN_IN = '2026-11-12 16:00:00';

    private InMemoryUserRepository $users;
    private InMemoryRefreshTokenRepository $tokens;
    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->tokens = new InMemoryRefreshTokenRepository();
        $this->clock = new MockClock(self::SIGN_IN);
        $this->user = UserMother::any('eve@example.com');
        $this->users->add($this->user);
    }

    public function test_it_issues_the_first_token_of_a_session_at_sign_in(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $sessionId = Uuid::v7()->toRfc4122();
        $secret = RefreshTokenSecret::generate()->value;

        $this->issueHandler()(new IssueRefreshTokenCommand($id, $sessionId, $this->user->getId()->toRfc4122(), $secret));

        $token = $this->tokens->findBySecretHash(RefreshTokenSecret::hashOf($secret)) ?? self::fail('Token expected.');
        self::assertSame($id, $token->getId()->toRfc4122());
        self::assertSame($sessionId, $token->getSessionId()->toRfc4122());
        self::assertSame($this->user, $token->getUser());
        self::assertEquals(new \DateTimeImmutable(self::SIGN_IN), $token->getSessionStartedAt());
    }

    public function test_it_refuses_to_issue_for_an_unknown_user(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->issueHandler()(new IssueRefreshTokenCommand(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), 'secret'));
    }

    public function test_it_rotates_the_presented_token_and_describes_the_successor(): void
    {
        [$token, $secret] = $this->signIn();
        $this->clock->modify('+20 minutes');
        $successorId = Uuid::v7()->toRfc4122();

        $this->rotate($secret, $successorId);

        self::assertNotNull($token->getRotatedAt());
        $view = new GetRefreshTokenHandler($this->tokens)(new GetRefreshTokenQuery($successorId)) ?? self::fail('View expected.');
        self::assertSame($this->user->getId()->toRfc4122(), $view->userId);
        self::assertSame('eve@example.com', $view->userEmail);
        self::assertEquals(new \DateTimeImmutable('2026-12-12 16:20:00'), $view->expiresAt);
        self::assertNull(new GetRefreshTokenHandler($this->tokens)(new GetRefreshTokenQuery(Uuid::v7()->toRfc4122())));
    }

    public function test_it_refuses_an_unknown_token(): void
    {
        $this->signIn();

        $this->expectException(InvalidRefreshTokenException::class);
        $this->rotate(RefreshTokenSecret::generate()->value);
    }

    public function test_it_revokes_the_whole_session_when_a_rotated_token_is_reused(): void
    {
        [$stolen, $secret] = $this->signIn();
        $successorSecret = $this->rotate($secret);
        $successor = $this->tokens->findBySecretHash(RefreshTokenSecret::hashOf($successorSecret)) ?? self::fail('Successor expected.');
        [$otherSession] = $this->signIn();
        $this->clock->modify('+31 seconds');

        try {
            $this->rotate($secret);
            self::fail('Expected InvalidRefreshTokenException.');
        } catch (InvalidRefreshTokenException) {
        }

        self::assertNotNull($successor->getRevokedAt());
        self::assertNull($stolen->getRevokedAt(), 'Already rotated: it can no longer be used anyway.');
        self::assertNull($otherSession->getRevokedAt());
        self::assertCount(3, $this->tokens->all(), 'No successor for a reused token.');
    }

    public function test_it_revokes_the_session_of_the_presented_token_at_sign_out(): void
    {
        [, $secret] = $this->signIn();
        $successorSecret = $this->rotate($secret);
        [$otherSession] = $this->signIn();

        $this->revokeHandler()(new RevokeSessionCommand($secret));

        self::assertSame([], $this->tokens->findUsableBySession(
            ($this->tokens->findBySecretHash(RefreshTokenSecret::hashOf($successorSecret)) ?? self::fail('Successor expected.'))->getSessionId(),
            $this->clock->now(),
        ));
        self::assertNull($otherSession->getRevokedAt());
    }

    public function test_it_signs_out_quietly_with_an_unknown_token(): void
    {
        [$token] = $this->signIn();

        $this->revokeHandler()(new RevokeSessionCommand(RefreshTokenSecret::generate()->value));

        self::assertNull($token->getRevokedAt());
    }

    /** @return array{RefreshToken, string} the token and its secret */
    private function signIn(): array
    {
        $secret = RefreshTokenSecret::generate();
        $token = RefreshToken::issueAtSignIn(Uuid::v7(), $this->user, Uuid::v7(), $secret, $this->clock->now());
        $this->tokens->add($token);

        return [$token, $secret->value];
    }

    /** @return string the successor's secret */
    private function rotate(string $presented, ?string $successorId = null): string
    {
        $successorSecret = RefreshTokenSecret::generate()->value;
        new RotateRefreshTokenHandler($this->tokens, $this->clock)(
            new RotateRefreshTokenCommand($presented, $successorId ?? Uuid::v7()->toRfc4122(), $successorSecret),
        );

        return $successorSecret;
    }

    private function issueHandler(): IssueRefreshTokenHandler
    {
        return new IssueRefreshTokenHandler($this->users, $this->tokens, $this->clock);
    }

    private function revokeHandler(): RevokeSessionHandler
    {
        return new RevokeSessionHandler($this->tokens, $this->clock);
    }
}
