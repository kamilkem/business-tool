<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingMailer;
use App\TimeTracking\Application\Command\ApproveShiftChangeRequest\ApproveShiftChangeRequestCommand;
use App\TimeTracking\Application\EventHandler\ApproveRequestOnShiftChangeRequestApplied;
use App\TimeTracking\Application\EventHandler\NotifyManagersOnShiftChangeRequestSubmitted;
use App\TimeTracking\Application\EventHandler\NotifyRequesterOnShiftChangeRequestDecided;
use App\TimeTracking\Application\EventHandler\ShiftChangeRequestEmail;
use App\TimeTracking\Domain\Event\ShiftChangeRequestApplied;
use App\TimeTracking\Domain\Event\ShiftChangeRequestDecided;
use App\TimeTracking\Domain\Event\ShiftChangeRequestSubmitted;
use App\TimeTracking\Domain\Model\ShiftChangeRequest;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ApproveRequestOnShiftChangeRequestApplied::class)]
#[CoversClass(NotifyManagersOnShiftChangeRequestSubmitted::class)]
#[CoversClass(NotifyRequesterOnShiftChangeRequestDecided::class)]
#[CoversClass(ShiftChangeRequestEmail::class)]
final class ShiftChangeRequestEventHandlersTest extends ShiftChangeRequestTestCase
{
    private RecordingMailer $mailer;
    private AppLinks $links;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new RecordingMailer();
        $this->links = new AppLinks('https://admin.example.com/', 'https://clock.example.com');
    }

    public function test_it_approves_the_request_once_its_change_is_applied(): void
    {
        $commandBus = new RecordingCommandBus();

        (new ApproveRequestOnShiftChangeRequestApplied($commandBus))(new ShiftChangeRequestApplied('org', 'request', 'shift', 'manager'));

        self::assertEquals([new ApproveShiftChangeRequestCommand('org', 'manager', 'request', 'shift')], $commandBus->commands);
    }

    public function test_it_tells_every_owner_and_manager_about_a_new_request_in_the_organizations_time_zone(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);
        $request = $this->newTimesRequest($this->employee, $shift, '2026-11-12 16:00:00', '2026-11-12 23:30:00', 'Forgot to clock out');

        $this->submitted($request, $this->employee);

        self::assertSame(['olivia@example.com', 'mark@example.com'], array_map(static fn (TransactionalEmail $email): string => $email->to, $this->mailer->emails));
        $email = $this->mailer->emails[1];
        $requesterName = trim($this->employee->getFirstName().' '.$this->employee->getLastName());
        self::assertSame($requesterName.' asks for a shift change', $email->subject);
        self::assertSame('email/shift_change_request_submitted.html.twig', $email->template);
        self::assertSame([
            'firstName' => $this->manager->getFirstName(),
            'kind' => 'new_times',
            'startedAt' => 'Thu 12 Nov 2026, 17:00',
            'endedAt' => 'Fri 13 Nov 2026, 00:30',
            'shiftStartedAt' => 'Thu 12 Nov 2026, 17:00',
            'shiftEndedAt' => null,
            'note' => 'Forgot to clock out',
            'timeZone' => 'Europe/Warsaw',
            'organizationName' => 'Corner Pub',
            'requesterName' => $requesterName,
            'link' => 'https://admin.example.com/',
        ], $email->context);
    }

    public function test_it_does_not_tell_a_manager_about_their_own_request_nor_deactivated_managers(): void
    {
        $secondManager = UserMother::any('maria@example.com');
        OrganizationMother::addMember($this->organization, $secondManager, MembershipRole::Manager, new \DateTimeImmutable('2026-01-05 09:00:00'));
        OrganizationMother::deactivateMember($this->organization, $secondManager, new \DateTimeImmutable('2026-11-01 09:00:00'));
        $request = $this->missingShiftRequest($this->manager, '2026-11-12 08:00:00', '2026-11-12 16:00:00');

        $this->submitted($request, $this->manager);

        self::assertSame(['olivia@example.com'], array_map(static fn (TransactionalEmail $email): string => $email->to, $this->mailer->emails));
        self::assertSame('missing_shift', $this->mailer->emails[0]->context['kind']);
        self::assertNull($this->mailer->emails[0]->context['shiftStartedAt']);
    }

    public function test_it_tells_the_requester_their_request_was_approved(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:30:00');
        $request->approve($this->manager->getId(), MembershipRole::Manager, Uuid::v7(), new \DateTimeImmutable(self::NOW));

        $this->decided($request, $this->manager);

        self::assertCount(1, $this->mailer->emails);
        $email = $this->mailer->emails[0];
        self::assertSame('eve@example.com', $email->to);
        self::assertSame('Your shift change request was approved', $email->subject);
        self::assertSame('email/shift_change_request_decided.html.twig', $email->template);
        self::assertTrue($email->context['approved']);
        self::assertSame('Thu 12 Nov 2026, 17:00', $email->context['startedAt']);
        self::assertSame(trim($this->manager->getFirstName().' '.$this->manager->getLastName()), $email->context['deciderName']);
        self::assertNull($email->context['rejectionReason']);
        self::assertSame('https://clock.example.com/', $email->context['link']);
    }

    public function test_it_tells_a_deactivated_requester_their_request_was_rejected_and_why(): void
    {
        $request = $this->missingShiftRequest($this->employee, '2026-11-12 16:00:00', '2026-11-12 23:30:00');
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable('2026-11-13 09:00:00'));
        $request->reject($this->owner->getId(), MembershipRole::Owner, 'You were off that day', new \DateTimeImmutable(self::NOW));

        $this->decided($request, $this->owner);

        self::assertCount(1, $this->mailer->emails);
        $email = $this->mailer->emails[0];
        self::assertSame('eve@example.com', $email->to);
        self::assertSame('Your shift change request was rejected', $email->subject);
        self::assertFalse($email->context['approved']);
        self::assertSame('You were off that day', $email->context['rejectionReason']);
    }

    public function test_it_links_a_manager_to_the_admin_panel_when_their_own_request_is_decided(): void
    {
        $request = $this->missingShiftRequest($this->manager, '2026-11-12 16:00:00', '2026-11-12 23:30:00');
        $request->reject($this->manager->getId(), MembershipRole::Manager, null, new \DateTimeImmutable(self::NOW));

        $this->decided($request, $this->manager);

        self::assertSame('https://admin.example.com/', $this->mailer->emails[0]->context['link']);
    }

    private function submitted(ShiftChangeRequest $request, User $requester): void
    {
        $handler = new NotifyManagersOnShiftChangeRequestSubmitted($this->requests, $this->shifts, $this->queryBus, $this->mailer, $this->links);
        $handler(new ShiftChangeRequestSubmitted($this->organizationId(), $request->getId()->toRfc4122(), $requester->getId()->toRfc4122()));
    }

    private function decided(ShiftChangeRequest $request, User $decider): void
    {
        $handler = new NotifyRequesterOnShiftChangeRequestDecided($this->requests, $this->queryBus, $this->mailer, $this->links);
        $handler(new ShiftChangeRequestDecided(
            $this->organizationId(),
            $request->getId()->toRfc4122(),
            $request->getUserId()->toRfc4122(),
            $decider->getId()->toRfc4122(),
            $request->getStatus()->value,
        ));
    }
}
