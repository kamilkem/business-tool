<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Language\Language;
use App\Tests\Unit\Shared\Application\RecordingMailer;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Application\Command\SendInvitation\SendInvitationCommand;
use App\User\Application\Command\SendInvitation\SendInvitationHandler;
use App\User\Domain\Exception\PasswordAlreadySetException;
use App\User\Domain\Model\PasswordToken;
use App\User\Domain\Model\PasswordTokenPurpose;
use App\User\Domain\Model\PasswordTokenSecret;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SendInvitationHandler::class)]
#[CoversClass(AppLinks::class)]
final class SendInvitationHandlerTest extends TestCase
{
    private InMemoryUserRepository $users;
    private InMemoryPasswordTokenRepository $tokens;
    private RecordingMailer $mailer;
    private ?OrganizationView $organization;
    private User $inviter;
    private User $invitee;
    private string $organizationId;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->tokens = new InMemoryPasswordTokenRepository();
        $this->mailer = new RecordingMailer();
        $this->organizationId = Uuid::v7()->toRfc4122();
        $this->organization = new OrganizationView($this->organizationId, 'Corner Pub', null, 'PL', 'PLN', 'Europe/Warsaw', 'semicolon', new \DateTimeImmutable());
        $this->inviter = new User(Uuid::v7(), 'olivia@example.com', new \DateTimeImmutable('2026-11-01 09:00:00'), 'Olivia', 'Owner');
        $this->inviter->changePassword('hash');
        $this->invitee = new User(Uuid::v7(), 'eve@example.com', new \DateTimeImmutable('2026-11-01 09:00:00'), 'Eve', 'Nowak');
        $this->users->add($this->inviter);
        $this->users->add($this->invitee);
    }

    public function test_it_issues_an_invitation_token_and_queues_the_email_with_its_link(): void
    {
        $tokenId = Uuid::v7()->toRfc4122();

        $this->handle('employee', $tokenId);

        $tokens = $this->tokens->all();
        self::assertCount(1, $tokens);
        self::assertSame($tokenId, $tokens[0]->getId()->toRfc4122());
        self::assertSame($this->invitee, $tokens[0]->getUser());
        self::assertSame(PasswordTokenPurpose::Invitation, $tokens[0]->getPurpose());
        self::assertEquals(new \DateTimeImmutable('2026-11-18 12:00:00'), $tokens[0]->getExpiresAt());

        $email = $this->onlyEmail();
        self::assertSame('eve@example.com', $email->to);
        self::assertSame('en', $email->locale);
        self::assertSame('invitation.subject', $email->subject);
        self::assertSame(['organizationName' => 'Corner Pub'], $email->subjectParameters);
        self::assertSame('email/invitation.html.twig', $email->template);
        self::assertSame(['Eve', 'Corner Pub', 'Olivia Owner', 7], [
            $email->context['firstName'],
            $email->context['organizationName'],
            $email->context['inviterName'],
            $email->context['validForDays'],
        ]);
        self::assertMatchesRegularExpression('~^https://clock\.example/set-password#token=([A-Za-z0-9_-]{43})$~', (string) $email->context['link']);
        self::assertSame($tokens[0]->getSecretHash(), PasswordTokenSecret::hashOf($this->secretFrom($email)));
    }

    /** @return iterable<string, array{string, non-empty-string}> */
    public static function appByRole(): iterable
    {
        yield 'owner' => ['owner', 'https://admin.example/set-password#token='];
        yield 'manager' => ['manager', 'https://admin.example/set-password#token='];
        yield 'employee' => ['employee', 'https://clock.example/set-password#token='];
    }

    /** @param non-empty-string $linkPrefix */
    #[DataProvider('appByRole')]
    public function test_it_links_owners_and_managers_to_the_admin_panel_and_employees_to_the_time_clock(string $role, string $linkPrefix): void
    {
        $this->handle($role);

        self::assertStringStartsWith($linkPrefix, (string) $this->onlyEmail()->context['link']);
    }

    public function test_it_writes_the_invitation_in_the_language_chosen_for_the_invitee(): void
    {
        $this->inviter->changeLanguage(Language::English);
        $this->invitee->changeLanguage(Language::Polish);

        $this->handle('employee');

        self::assertSame('pl', $this->onlyEmail()->locale);
    }

    public function test_it_writes_the_invitation_in_the_inviters_language_when_none_was_chosen_for_the_invitee(): void
    {
        $this->inviter->changeLanguage(Language::Polish);

        $this->handle('employee');

        self::assertSame('pl', $this->onlyEmail()->locale);
    }

    public function test_it_invites_without_an_inviter_when_the_account_was_set_up_from_the_console(): void
    {
        $this->invitee->changeLanguage(Language::Polish);

        $this->handle('owner', invitedById: null);

        $email = $this->onlyEmail();
        self::assertSame('pl', $email->locale);
        self::assertNull($email->context['inviterName']);
        self::assertSame('Corner Pub', $email->context['organizationName']);
        self::assertStringStartsWith('https://admin.example/set-password#token=', (string) $email->context['link']);
    }

    public function test_it_invalidates_the_previous_invitation_but_not_a_password_reset(): void
    {
        $issuedAt = new \DateTimeImmutable('2026-11-10 12:00:00');
        $previous = new PasswordToken(Uuid::v7(), $this->invitee, PasswordTokenPurpose::Invitation, PasswordTokenSecret::generate(), $issuedAt);
        $reset = new PasswordToken(Uuid::v7(), $this->invitee, PasswordTokenPurpose::PasswordReset, PasswordTokenSecret::generate(), new \DateTimeImmutable('2026-11-11 11:30:00'));
        $this->tokens->add($previous);
        $this->tokens->add($reset);

        $this->handle('employee');

        self::assertEquals(new \DateTimeImmutable('2026-11-11 12:00:00'), $previous->getInvalidatedAt());
        self::assertNull($reset->getInvalidatedAt());
        self::assertCount(1, $this->tokens->findUnusedByUser($this->invitee->getId(), PasswordTokenPurpose::Invitation));
    }

    public function test_it_refuses_a_user_who_already_has_a_password(): void
    {
        $this->invitee->changePassword('hash');

        try {
            $this->handle('employee');
            self::fail('Expected PasswordAlreadySetException.');
        } catch (PasswordAlreadySetException) {
        }

        self::assertSame([], $this->tokens->all());
        self::assertSame([], $this->mailer->emails);
    }

    public function test_it_refuses_an_organization_the_inviter_does_not_belong_to(): void
    {
        $this->organization = null;

        $this->expectException(OrganizationNotFoundException::class);

        $this->handle('employee');
    }

    private function handle(string $role, ?string $tokenId = null, ?string $invitedById = 'inviter'): void
    {
        $invitedById = 'inviter' === $invitedById ? $this->inviter->getId()->toRfc4122() : $invitedById;
        // The organization is read as the inviter, or as the invitee when nobody invited them.
        $viewerId = $invitedById ?? $this->invitee->getId()->toRfc4122();
        $queryBus = new StubQueryBus(fn (QueryInterface $query): ?OrganizationView => $query instanceof GetOrganizationQuery
            && $query->organizationId === $this->organizationId
            && $query->userId === $viewerId ? $this->organization : null);

        new SendInvitationHandler(
            $this->users,
            $this->tokens,
            $queryBus,
            $this->mailer,
            new AppLinks('https://admin.example/', 'https://clock.example'),
            new MockClock('2026-11-11 12:00:00'),
        )(new SendInvitationCommand(
            tokenId: $tokenId ?? Uuid::v7()->toRfc4122(),
            userId: $this->invitee->getId()->toRfc4122(),
            organizationId: $this->organizationId,
            role: $role,
            invitedById: $invitedById,
        ));
    }

    private function onlyEmail(): TransactionalEmail
    {
        self::assertCount(1, $this->mailer->emails);

        return $this->mailer->emails[0];
    }

    private function secretFrom(TransactionalEmail $email): string
    {
        return explode('#token=', (string) $email->context['link'])[1];
    }
}
