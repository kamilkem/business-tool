<?php

declare(strict_types=1);

namespace App\Tests\Functional\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Job\ReportLongShifts\ReportLongShiftsJob;
use App\TimeTracking\Application\Job\StartLongShiftReports\StartLongShiftReportsJob;
use App\TimeTracking\Domain\Model\Shift;
use App\TimeTracking\Domain\Repository\ShiftRepositoryInterface;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Forgotten clock-outs (#33) in the container: the fan-out queues the organization's job, which runs
 * in its tenant on the job bus (as on the jobs worker), marks the shift and queues the e-mails once.
 */
final class LongShiftReportJobTest extends ApiTestCase
{
    private const string NOW = '2026-11-23 10:00:00';

    public function test_it_queues_the_job_of_each_organization_and_e_mails_the_person_and_the_managers_once(): void
    {
        self::mockTime(self::NOW);
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = UserFactory::createOne(['email' => 'eve@example.com']);
        OrganizationMother::addMember($organization, $employee, MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $shiftId = $this->clockIn($organization, $employee, '2026-11-22 21:30:00');
        [, $quiet] = self::memberWithRole(MembershipRole::Owner);
        $this->clockIn($quiet, OrganizationMother::ownerOf($quiet), '2026-11-23 09:30:00');

        $this->handleJob(new StartLongShiftReportsJob());

        $queued = $this->jobs()->getSent();
        self::assertEquals([new ReportLongShiftsJob($organization->getId()->toRfc4122())], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $queued));
        self::assertSame('long-shift-report:'.$organization->getId()->toRfc4122(), (string) $queued[0]->last(DeduplicateStamp::class)?->getKey());

        $this->handleJob(new ReportLongShiftsJob($organization->getId()->toRfc4122()));

        self::assertQueuedEmailCount(2);
        self::assertEmailAddressContains(self::getMailerMessage(0) ?? self::fail(), 'To', 'eve@example.com');
        self::assertEmailAddressContains(self::getMailerMessage(1) ?? self::fail(), 'To', $owner->getEmail());
        self::assertFalse(static::getContainer()->get(TenantContextInterface::class)->hasOrganization());

        $shift = $this->shift($organization, $shiftId);
        self::assertTrue($shift->isOpen());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $shift->getLongShiftReportedAt());

        // An hour later: nothing new to report.
        self::mockTime('2026-11-23 11:00:00');
        $this->handleJob(new ReportLongShiftsJob($organization->getId()->toRfc4122()));
        self::assertQueuedEmailCount(2);
    }

    public function test_the_console_command_queues_the_fan_out_or_one_organizations_report(): void
    {
        $application = new Application(self::bootKernel());
        $organizationId = Uuid::v7()->toRfc4122();

        self::assertSame(0, (new CommandTester($application->find('app:time-tracking:report-long-shifts')))->execute([]));
        self::assertSame(0, (new CommandTester($application->find('app:time-tracking:report-long-shifts')))->execute(['--organization' => $organizationId]));
        self::assertSame(2, (new CommandTester($application->find('app:time-tracking:report-long-shifts')))->execute(['--organization' => 'not-an-id']));

        $sent = $this->jobs()->getSent();
        self::assertEquals([new StartLongShiftReportsJob(), new ReportLongShiftsJob($organizationId)], array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $sent));
        self::assertSame('long-shift-report:'.$organizationId, (string) $sent[1]->last(DeduplicateStamp::class)?->getKey());
    }

    private function clockIn(Organization $organization, User $user, string $startedAt): string
    {
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($organization);
        $shift = Shift::clockIn(Uuid::v7(), $user->getId(), new \DateTimeImmutable($startedAt), null);
        static::getContainer()->get(ShiftRepositoryInterface::class)->add($shift);
        $tenantContext->reset();

        return $shift->getId()->toRfc4122();
    }

    private function shift(Organization $organization, string $id): Shift
    {
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($organization);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $shift = static::getContainer()->get(ShiftRepositoryInterface::class)->findById(Uuid::fromString($id));
        $tenantContext->reset();

        return $shift ?? self::fail('Shift expected.');
    }

    /** Handles the job as the jobs worker does: received from the `jobs` transport. */
    private function handleJob(object $job): void
    {
        /** @var MessageBusInterface $bus */
        $bus = static::getContainer()->get('messenger.bus.job');
        $bus->dispatch(new Envelope($job, [new ReceivedStamp('jobs')]));
    }

    private function jobs(): InMemoryTransport
    {
        /** @var InMemoryTransport */
        return static::getContainer()->get('messenger.transport.jobs');
    }
}
