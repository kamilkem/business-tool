<?php

declare(strict_types=1);

namespace App\Tests\Api\TimeTracking;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Api\ApiTestCase;
use App\Tests\Factory\UserFactory;
use App\Tests\Fixtures\OrganizationMother;
use App\TimeTracking\Application\Command\LockShiftPeriod\LockShiftPeriodCommand;
use App\TimeTracking\Application\Command\UnlockShiftPeriod\UnlockShiftPeriodCommand;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A locked shift period (an approved payroll month, ADR 0027 §8): every shift write and shift change
 * request about a shift that started in it is a 409, and the open month next to it is not affected.
 * October 2026 is locked through LockShiftPeriodCommand, as Payroll's event handler will (#62); the
 * organization is in Europe/Warsaw unless a test changes it. The server's time is mocked at NOW (UTC).
 */
final class ShiftPeriodLockApiTest extends ApiTestCase
{
    private const string NOW = '2026-11-13 12:00:00';
    private const array MERGE_PATCH = ['Content-Type' => 'application/merge-patch+json'];

    public function test_it_refuses_to_enter_a_shift_that_started_in_the_locked_month_and_enters_one_in_the_next(): void
    {
        [$manager, $organization] = self::memberWithRole(MembershipRole::Manager);
        $employee = self::addMember($organization, MembershipRole::Employee);
        self::lockOctober($organization);
        self::mockTime(self::NOW);

        // A night that started on the last day of October belongs to October, though it ends in November.
        $response = self::add($manager, $organization, self::body($employee, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00'));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('2026-10-31', self::detail($response));

        // 23:30 UTC on 31 October is already 1 November in Warsaw.
        self::add($manager, $organization, self::body($employee, '2026-10-31T23:30:00Z', '2026-11-01T06:00:00Z'));
        self::assertResponseStatusCodeSame(201);

        self::assertSame([], self::list($manager, $organization, '2026-10-01', '2026-10-31'));
        self::assertSame(['2026-11-01'], array_column(self::list($manager, $organization, '2026-11-01'), 'date'));
    }

    public function test_it_refuses_to_correct_a_shift_of_the_locked_month_or_to_move_one_into_it(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $october = self::workShift($employee, $organization, '2026-10-31 19:00:00', '2026-11-01 02:00:00');
        $november = self::workShift($employee, $organization, '2026-11-02 16:00:00', '2026-11-02 23:00:00');
        self::lockOctober($organization);
        self::mockTime(self::NOW);

        self::correct($owner, $organization, $october, ['endedAt' => '2026-11-01T01:00:00Z']);
        self::assertResponseStatusCodeSame(409);
        self::correct($owner, $organization, $november, ['startedAt' => '2026-10-30T16:00:00Z', 'endedAt' => '2026-10-30T23:00:00Z']);
        self::assertResponseStatusCodeSame(409);

        // Saving what the shift already has changes nothing, so it is not refused.
        self::correct($owner, $organization, $october, ['endedAt' => '2026-11-01T02:00:00Z']);
        self::assertResponseStatusCodeSame(200);

        self::correct($owner, $organization, $november, ['endedAt' => '2026-11-02T22:00:00Z']);
        self::assertResponseStatusCodeSame(200);

        $shifts = self::list($owner, $organization, '2026-10-31', '2026-11-02');
        self::assertSame(
            [[$november, '2026-11-02T22:00:00+00:00', $owner->getId()->toRfc4122()], [$october, '2026-11-01T02:00:00+00:00', null]],
            array_map(static fn (array $shift): array => [$shift['id'], $shift['endedAt'], $shift['correctedBy']], $shifts),
        );
    }

    public function test_it_refuses_shift_change_requests_about_the_locked_month(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $october = self::workShift($employee, $organization, '2026-10-30 16:00:00', '2026-10-30 23:00:00');
        $november = self::workShift($employee, $organization, '2026-11-02 16:00:00', '2026-11-02 23:00:00');
        self::lockOctober($organization);
        self::mockTime(self::NOW);

        $refused = [
            'missing shift' => ['shiftId' => null, 'startedAt' => '2026-10-29T16:00:00Z', 'endedAt' => '2026-10-29T23:00:00Z'],
            'new times for a locked shift' => ['shiftId' => $october, 'startedAt' => '2026-10-30T15:00:00Z', 'endedAt' => '2026-10-30T23:00:00Z'],
            'new times moving a shift into the locked month' => ['shiftId' => $november, 'startedAt' => '2026-10-31T16:00:00Z', 'endedAt' => '2026-10-31T23:00:00Z'],
        ];
        foreach ($refused as $case => $body) {
            self::submit($employee, $organization, $body + ['note' => 'Please fix it']);
            self::assertResponseStatusCodeSame(409, $case);
        }

        self::submit($employee, $organization, ['shiftId' => $november, 'startedAt' => '2026-11-02T15:00:00Z', 'endedAt' => '2026-11-02T23:00:00Z', 'note' => 'Came in early']);
        self::assertResponseStatusCodeSame(201);
        self::submit($employee, $organization, ['shiftId' => null, 'startedAt' => '2026-11-03T16:00:00Z', 'endedAt' => '2026-11-03T23:00:00Z', 'note' => 'Phone was dead']);
        self::assertResponseStatusCodeSame(201);

        self::assertCount(2, self::requests($owner, $organization));
    }

    public function test_it_refuses_to_approve_a_request_that_was_pending_when_the_month_was_locked(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $october = self::workShift($employee, $organization, '2026-10-30 16:00:00', '2026-10-30 23:00:00');
        self::mockTime(self::NOW);
        $newTimes = self::submitted($employee, $organization, ['shiftId' => $october, 'startedAt' => '2026-10-30T15:00:00Z', 'endedAt' => '2026-10-30T23:00:00Z']);
        $missing = self::submitted($employee, $organization, ['shiftId' => null, 'startedAt' => '2026-10-29T16:00:00Z', 'endedAt' => '2026-10-29T23:00:00Z']);
        $openMonth = self::submitted($employee, $organization, ['shiftId' => null, 'startedAt' => '2026-11-03T16:00:00Z', 'endedAt' => '2026-11-03T23:00:00Z']);
        self::lockOctober($organization);

        foreach ([$newTimes, $missing] as $requestId) {
            self::approve($owner, $organization, $requestId);
            self::assertResponseStatusCodeSame(409);
        }
        self::approve($owner, $organization, $openMonth);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(
            [$openMonth => 'approved', $missing => 'pending', $newTimes => 'pending'],
            array_column(self::requests($owner, $organization), 'status', 'id'),
        );
        self::assertSame(['2026-10-30T16:00:00+00:00'], array_column(self::list($owner, $organization, '2026-10-01', '2026-10-31'), 'startedAt'));

        // Rejecting changes no shift, so the manager can still clear the pending ones.
        self::reject($owner, $organization, $missing);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_lets_shifts_change_again_once_the_month_is_unlocked(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $employee = self::addMember($organization, MembershipRole::Employee);
        $october = self::workShift($employee, $organization, '2026-10-30 16:00:00', '2026-10-30 23:00:00');
        $runId = self::lockOctober($organization);
        self::mockTime(self::NOW);

        self::correct($owner, $organization, $october, ['endedAt' => '2026-10-30T22:00:00Z']);
        self::assertResponseStatusCodeSame(409);

        self::inTenant($organization, static fn (CommandBusInterface $commandBus) => $commandBus->handle(
            new UnlockShiftPeriodCommand($organization->getId()->toRfc4122(), $runId),
        ));

        self::correct($owner, $organization, $october, ['endedAt' => '2026-10-30T22:00:00Z']);
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_decides_by_the_business_date_in_the_organizations_time_zone(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $organization->changeSettings($owner->getId(), $organization->getName(), null, 'US', 'USD', 'America/New_York');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::lockOctober($organization);
        self::mockTime(self::NOW);

        // 1 November 02:00 UTC is still 31 October (22:00) in New York.
        self::add($owner, $organization, self::body($owner, '2026-11-01T02:00:00Z', '2026-11-01T08:00:00Z'));
        self::assertResponseStatusCodeSame(409);

        // 1 November 04:00 UTC is 1 November (00:00, EDT ends that night) in New York.
        self::add($owner, $organization, self::body($owner, '2026-11-01T04:00:00Z', '2026-11-01T08:00:00Z'));
        self::assertResponseStatusCodeSame(201);
    }

    public function test_it_does_not_check_clocking_in_and_out(): void
    {
        [$employee, $organization] = self::memberWithRole(MembershipRole::Employee);
        // Payroll locks only a month that is over (ADR 0027 §7); clocking happens at the server's now.
        self::lock($organization, '2026-11-01', '2026-11-30');

        self::workShift($employee, $organization, '2026-11-13 08:00:00', '2026-11-13 12:00:00');
    }

    public function test_it_is_not_affected_by_another_organizations_lock(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        [$otherOwner, $otherOrganization] = self::memberWithRole(MembershipRole::Owner);
        self::lockOctober($otherOrganization);
        self::mockTime(self::NOW);

        self::add($owner, $organization, self::body($owner, '2026-10-30T16:00:00Z', '2026-10-30T23:00:00Z'));
        self::assertResponseStatusCodeSame(201);

        self::add($otherOwner, $otherOrganization, self::body($otherOwner, '2026-10-30T16:00:00Z', '2026-10-30T23:00:00Z'));
        self::assertResponseStatusCodeSame(409);
    }

    public function test_it_locks_once_per_locker(): void
    {
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);
        $runId = self::lockOctober($organization);
        self::lock($organization, '2026-10-01', '2026-10-31', $runId);

        $count = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM locked_shift_period WHERE organization_id = ?', [$organization->getId()->toRfc4122()]);
        self::assertSame(1, $count);

        self::mockTime(self::NOW);
        self::add($owner, $organization, self::body($owner, '2026-10-30T16:00:00Z', '2026-10-30T23:00:00Z'));
        self::assertResponseStatusCodeSame(409);
    }

    /** Locks October 2026 for a new payroll run id; returns the id. */
    private static function lockOctober(Organization $organization): string
    {
        return self::lock($organization, '2026-10-01', '2026-10-31');
    }

    private static function lock(Organization $organization, string $firstDate, string $lastDate, ?string $lockedBy = null): string
    {
        $lockedBy ??= Uuid::v7()->toRfc4122();
        self::inTenant($organization, static fn (CommandBusInterface $commandBus) => $commandBus->handle(new LockShiftPeriodCommand(
            $organization->getId()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            $lockedBy,
            $firstDate,
            $lastDate,
        )));

        return $lockedBy;
    }

    /** Runs the command as Payroll's event handler would: in the organization's tenant context. */
    private static function inTenant(Organization $organization, \Closure $action): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $tenantContext = static::getContainer()->get(TenantContextInterface::class);
        $tenantContext->switchTo($entityManager->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.'));
        try {
            $action(static::getContainer()->get(CommandBusInterface::class));
        } finally {
            $tenantContext->reset();
            $entityManager->clear();
        }
    }

    /** @param array<string, mixed> $body */
    private static function add(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shifts', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function correct(User $user, Organization $organization, string $shiftId, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('PATCH', '/api/shifts/'.$shiftId, [
            'json' => $body,
            'headers' => self::MERGE_PATCH,
        ]);
    }

    /** @return array{userId: string, startedAt: string, endedAt: string} */
    private static function body(User $person, string $startedAt, string $endedAt): array
    {
        return ['userId' => $person->getId()->toRfc4122(), 'startedAt' => $startedAt, 'endedAt' => $endedAt];
    }

    /** @param array<string, mixed> $body */
    private static function submit(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests', ['json' => $body]);
    }

    /** @param array<string, mixed> $body */
    private static function submitted(User $user, Organization $organization, array $body): string
    {
        $response = self::submit($user, $organization, $body + ['note' => 'Please fix it']);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    private static function approve(User $user, Organization $organization, string $requestId): void
    {
        static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/approve');
    }

    private static function reject(User $user, Organization $organization, string $requestId): void
    {
        static::clientFor($user, $organization)->request('POST', '/api/shift-change-requests/'.$requestId.'/reject', ['json' => []]);
    }

    /** @return list<array<string, mixed>> */
    private static function requests(User $user, Organization $organization): array
    {
        /** @var list<array<string, mixed>> */
        return static::clientFor($user, $organization)->request('GET', '/api/shift-change-requests')->toArray();
    }

    /** @return list<array<string, mixed>> the shifts that started on these business dates, newest first */
    private static function list(User $user, Organization $organization, string $from, ?string $to = null): array
    {
        /** @var list<array<string, mixed>> */
        return static::clientFor($user, $organization)
            ->request('GET', '/api/shifts', ['query' => ['from' => $from, 'to' => $to ?? $from]])
            ->toArray();
    }

    private static function detail(ResponseInterface $response): string
    {
        $body = $response->toArray(false);
        self::assertIsString($body['detail'] ?? null);

        return $body['detail'];
    }

    /** Clocks the person in and out at those server times (UTC); returns the shift's id. */
    private static function workShift(User $user, Organization $organization, string $startedAt, string $endedAt): string
    {
        self::mockTime($startedAt);
        $shift = static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-in')->toArray();
        self::mockTime($endedAt);
        static::clientFor($user, $organization)->request('POST', '/api/shifts/clock-out');
        self::assertResponseStatusCodeSame(200);

        return $shift['id'];
    }

    private static function addMember(Organization $organization, MembershipRole $role): User
    {
        $user = UserFactory::createOne();
        OrganizationMother::addMember($organization, $user, $role);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }
}
