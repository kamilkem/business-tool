<?php

declare(strict_types=1);

namespace App\Tests\Unit\TimeTracking\Application;

use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Link\AppLinks;
use App\Shared\Application\Mail\TransactionalEmail;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Language\Language;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Unit\Organization\Application\InMemoryTenantContext;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\RecordingJobBus;
use App\Tests\Unit\Shared\Application\RecordingMailer;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\TimeTracking\Application\Command\ChangeTimeTrackingSettings\ChangeTimeTrackingSettingsCommand;
use App\TimeTracking\Application\Command\ChangeTimeTrackingSettings\ChangeTimeTrackingSettingsHandler;
use App\TimeTracking\Application\Command\ReportLongShift\ReportLongShiftCommand;
use App\TimeTracking\Application\Command\ReportLongShift\ReportLongShiftHandler;
use App\TimeTracking\Application\EventHandler\NotifyOnLongShiftReported;
use App\TimeTracking\Application\Job\ReportLongShifts\ReportLongShiftsJob;
use App\TimeTracking\Application\Job\ReportLongShifts\ReportLongShiftsJobHandler;
use App\TimeTracking\Application\Job\StartLongShiftReports\StartLongShiftReportsJob;
use App\TimeTracking\Application\Job\StartLongShiftReports\StartLongShiftReportsJobHandler;
use App\TimeTracking\Application\MaxShiftLengths;
use App\TimeTracking\Application\Query\ListOrganizationsWithLongShifts\ListOrganizationsWithLongShiftsHandler;
use App\TimeTracking\Application\Query\ListOrganizationsWithLongShifts\ListOrganizationsWithLongShiftsQuery;
use App\TimeTracking\Domain\Event\LongShiftReported;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Uid\Uuid;

/**
 * Forgotten clock-outs (#33): the hourly fan-out, the organization's job, the report command and
 * the e-mails, over the organization of ShiftChangeRequestTestCase (Europe/Warsaw, NOW 12:00 UTC).
 */
#[CoversClass(StartLongShiftReportsJobHandler::class)]
#[CoversClass(ListOrganizationsWithLongShiftsHandler::class)]
#[CoversClass(ReportLongShiftsJobHandler::class)]
#[CoversClass(ReportLongShiftHandler::class)]
#[CoversClass(NotifyOnLongShiftReported::class)]
final class LongShiftReportsTest extends ShiftChangeRequestTestCase
{
    private InMemoryTimeTrackingSettingsRepository $settings;
    private InMemoryTenantContext $tenantContext;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new InMemoryTimeTrackingSettingsRepository();
        $this->tenantContext = new InMemoryTenantContext();
        $this->tenantContext->switchTo($this->organization);
        $this->mailer = new RecordingMailer();
        $this->shifts->organizationId = $this->organizationId();
    }

    public function test_it_queues_one_deduplicated_job_per_organization_with_an_open_shift_older_than_an_hour(): void
    {
        $jobBus = new RecordingJobBus();
        $queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof ListOrganizationsWithLongShiftsQuery => (new ListOrganizationsWithLongShiftsHandler($this->shifts))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });
        $fanOut = new StartLongShiftReportsJobHandler($queryBus, $jobBus, $this->clock);

        $this->clocked($this->employee, '2026-11-13 11:30:00', null);
        $fanOut(new StartLongShiftReportsJob());
        self::assertSame([], $jobBus->dispatched, 'Half an hour is within every limit.');

        $this->clocked($this->otherEmployee, '2026-11-13 10:59:00', null);
        $fanOut(new StartLongShiftReportsJob());

        self::assertEquals([[
            'job' => new ReportLongShiftsJob($this->organizationId()),
            'deduplicationKey' => 'long-shift-report:'.$this->organizationId(),
        ]], $jobBus->dispatched);
    }

    public function test_it_reports_each_open_shift_past_the_organizations_limit_once(): void
    {
        $this->setMaxShiftHours(10);
        $long = $this->clocked($this->employee, '2026-11-13 01:00:00', null);
        $this->clocked($this->otherEmployee, '2026-11-13 03:00:00', null);
        $this->clocked($this->manager, '2026-11-12 08:00:00', '2026-11-12 23:00:00');

        $this->runJob();

        self::assertEquals([new LongShiftReported($this->organizationId(), $long->getId()->toRfc4122(), $this->employee->getId()->toRfc4122(), 10)], $this->events->events);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $long->getLongShiftReportedAt());
        self::assertTrue($long->isOpen(), 'The shift is never ended for the person.');

        $this->clock->modify('+1 hour');
        $this->runJob();
        self::assertCount(1, $this->events->events, 'The second is exactly 10 hours long: within the limit.');

        $this->clock->modify('+1 hour');
        $this->runJob();
        self::assertCount(2, $this->events->events, 'The first is not reported again; the second is now past 10 hours.');
    }

    public function test_it_reports_nothing_for_a_shift_ended_or_deleted_since_it_was_listed(): void
    {
        $ended = $this->clocked($this->employee, '2026-11-12 20:00:00', null);
        $ended->clockOut(new \DateTimeImmutable(self::NOW));
        $handler = $this->reportHandler();

        $handler(new ReportLongShiftCommand($this->organizationId(), $ended->getId()->toRfc4122()));
        $handler(new ReportLongShiftCommand($this->organizationId(), Uuid::v7()->toRfc4122()));

        self::assertSame([], $this->events->events);
        self::assertSame(0, $this->shifts->saveCallCount);
    }

    public function test_it_refuses_to_report_in_another_organization_than_the_tenants(): void
    {
        $this->expectException(\LogicException::class);

        $this->reportHandler()(new ReportLongShiftCommand(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()));
    }

    public function test_it_asks_the_person_and_tells_every_other_owner_and_manager_in_their_language(): void
    {
        $this->manager->changeLanguage(Language::Polish);
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);

        $this->notify($shift->getId()->toRfc4122(), $this->employee->getId()->toRfc4122());

        self::assertSame(['eve@example.com', 'olivia@example.com', 'mark@example.com'], array_map(static fn (TransactionalEmail $email): string => $email->to, $this->mailer->emails));
        [$toEmployee, , $toManager] = $this->mailer->emails;
        self::assertSame('en', $toEmployee->locale);
        self::assertSame('long_shift_employee.subject', $toEmployee->subject);
        self::assertSame('email/long_shift_employee.html.twig', $toEmployee->template);
        self::assertSame([
            'firstName' => $this->employee->getFirstName(),
            'startedAt' => 'Thu 12 Nov 2026, 17:00',
            'link' => 'https://clock.example.com/',
            'organizationName' => 'Corner Pub',
            'hours' => 20,
            'maxShiftHours' => 12,
            'timeZone' => 'Europe/Warsaw',
        ], $toEmployee->context);

        $employeeName = trim($this->employee->getFirstName().' '.$this->employee->getLastName());
        self::assertSame('pl', $toManager->locale);
        self::assertSame('long_shift_manager.subject', $toManager->subject);
        self::assertSame(['employeeName' => $employeeName], $toManager->subjectParameters);
        self::assertSame('email/long_shift_manager.html.twig', $toManager->template);
        self::assertSame([
            'firstName' => $this->manager->getFirstName(),
            'employeeName' => $employeeName,
            'startedAt' => 'czw. 12 lis 2026, 17:00',
            'link' => 'https://admin.example.com/time',
            'organizationName' => 'Corner Pub',
            'hours' => 20,
            'maxShiftHours' => 12,
            'timeZone' => 'Europe/Warsaw',
        ], $toManager->context);
    }

    public function test_it_does_not_send_a_manager_the_managers_e_mail_about_their_own_shift(): void
    {
        $shift = $this->clocked($this->manager, '2026-11-12 16:00:00', null);

        $this->notify($shift->getId()->toRfc4122(), $this->manager->getId()->toRfc4122());

        self::assertSame(['email/long_shift_employee.html.twig', 'email/long_shift_manager.html.twig'], array_map(static fn (TransactionalEmail $email): string => $email->template, $this->mailer->emails));
        self::assertSame(['mark@example.com', 'olivia@example.com'], array_map(static fn (TransactionalEmail $email): string => $email->to, $this->mailer->emails));
    }

    public function test_it_tells_only_the_managers_about_a_deactivated_persons_shift(): void
    {
        $shift = $this->clocked($this->employee, '2026-11-12 16:00:00', null);
        OrganizationMother::deactivateMember($this->organization, $this->employee, new \DateTimeImmutable(self::NOW));

        $this->notify($shift->getId()->toRfc4122(), $this->employee->getId()->toRfc4122());

        self::assertSame(['olivia@example.com', 'mark@example.com'], array_map(static fn (TransactionalEmail $email): string => $email->to, $this->mailer->emails));
    }

    private function setMaxShiftHours(int $hours): void
    {
        (new ChangeTimeTrackingSettingsHandler($this->settings, $this->queryBus, $this->clock))(
            new ChangeTimeTrackingSettingsCommand($this->organizationId(), $this->owner->getId()->toRfc4122(), Uuid::v7()->toRfc4122(), $hours),
        );
    }

    private function runJob(): void
    {
        $handler = $this->reportHandler();
        $commandBus = new RecordingCommandBus(static function (CommandInterface $command) use ($handler): void {
            \assert($command instanceof ReportLongShiftCommand);
            $handler($command);
        });

        (new ReportLongShiftsJobHandler($this->shifts, new MaxShiftLengths($this->settings), $commandBus, $this->clock))(new ReportLongShiftsJob($this->organizationId()));
    }

    private function reportHandler(): ReportLongShiftHandler
    {
        return new ReportLongShiftHandler($this->shifts, new MaxShiftLengths($this->settings), $this->tenantContext, $this->events, $this->clock);
    }

    private function notify(string $shiftId, string $userId): void
    {
        $links = new AppLinks('https://admin.example.com/', 'https://clock.example.com');
        (new NotifyOnLongShiftReported($this->shifts, $this->queryBus, $this->mailer, $links, $this->clock))(
            new LongShiftReported($this->organizationId(), $shiftId, $userId, 12),
        );
    }
}
