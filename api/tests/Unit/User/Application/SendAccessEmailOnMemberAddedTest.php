<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Application;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Organization\Domain\Event\MemberAdded;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingMailer;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Application\Command\SendInvitation\SendInvitationCommand;
use App\User\Application\EventHandler\SendAccessEmailOnMemberAdded;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SendAccessEmailOnMemberAdded::class)]
final class SendAccessEmailOnMemberAddedTest extends TestCase
{
    private InMemoryUserRepository $users;
    private RecordingCommandBus $commandBus;
    private RecordingMailer $mailer;
    private User $owner;
    private User $member;
    private string $organizationId;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->commandBus = new RecordingCommandBus();
        $this->mailer = new RecordingMailer();
        $this->organizationId = Uuid::v7()->toRfc4122();
        $this->owner = new User(Uuid::v7(), 'olivia@example.com', new \DateTimeImmutable('2026-11-01 09:00:00'), 'Olivia', 'Owner');
        $this->owner->changePassword('hash');
        $this->member = new User(Uuid::v7(), 'eve@example.com', new \DateTimeImmutable('2026-11-01 09:00:00'), 'Eve', 'Nowak');
        $this->users->add($this->owner);
        $this->users->add($this->member);
    }

    public function test_it_sends_an_invitation_to_a_member_without_a_password(): void
    {
        $this->handle('employee');

        self::assertCount(1, $this->commandBus->commands);
        $command = $this->commandBus->commands[0];
        self::assertInstanceOf(SendInvitationCommand::class, $command);
        self::assertTrue(Uuid::isValid($command->tokenId));
        self::assertSame(
            [$this->member->getId()->toRfc4122(), $this->organizationId, 'employee', $this->owner->getId()->toRfc4122()],
            [$command->userId, $command->organizationId, $command->role, $command->invitedById],
        );
        self::assertSame([], $this->mailer->emails);
    }

    /** @return iterable<string, array{string, string}> */
    public static function signInLinkByRole(): iterable
    {
        yield 'manager' => ['manager', 'https://admin.example/login'];
        yield 'employee' => ['employee', 'https://clock.example/login'];
    }

    #[DataProvider('signInLinkByRole')]
    public function test_it_notifies_a_member_who_can_already_sign_in_without_issuing_a_token(string $role, string $link): void
    {
        $this->member->changePassword('hash');

        $this->handle($role);

        self::assertSame([], $this->commandBus->commands);
        self::assertCount(1, $this->mailer->emails);
        $email = $this->mailer->emails[0];
        self::assertSame('eve@example.com', $email->to);
        self::assertSame('en', $email->locale);
        self::assertSame('added_to_organization.subject', $email->subject);
        self::assertSame(['organizationName' => 'Corner Pub'], $email->subjectParameters);
        self::assertSame('email/added_to_organization.html.twig', $email->template);
        self::assertSame([
            'firstName' => 'Eve',
            'organizationName' => 'Corner Pub',
            'addedByName' => 'Olivia Owner',
            'link' => $link,
        ], $email->context);
    }

    private function handle(string $role): void
    {
        $organization = new OrganizationView($this->organizationId, 'Corner Pub', null, 'PL', 'PLN', 'Europe/Warsaw', 'semicolon', new \DateTimeImmutable());
        $queryBus = new StubQueryBus(fn (QueryInterface $query): ?OrganizationView => $query instanceof GetOrganizationQuery
            && $query->organizationId === $this->organizationId ? $organization : null);

        new SendAccessEmailOnMemberAdded(
            $this->users,
            $this->commandBus,
            $queryBus,
            $this->mailer,
            new AppLinks('https://admin.example', 'https://clock.example'),
        )(new MemberAdded(
            organizationId: $this->organizationId,
            userId: $this->member->getId()->toRfc4122(),
            role: $role,
            addedById: $this->owner->getId()->toRfc4122(),
        ));
    }
}
