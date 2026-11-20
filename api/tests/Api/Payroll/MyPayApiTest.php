<?php

declare(strict_types=1);

namespace App\Tests\Api\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /my-pay (#66, ADR 0027 §10). Organizations are in PLN and Europe/Warsaw, created on
 * 1 September 2026; the server's time is then mocked at NOW (20 November 2026, UTC), so October has
 * ended and November is the current month.
 */
final class MyPayApiTest extends PayrollApiTestCase
{
    public function test_it_estimates_this_month_and_counts_a_night_across_the_month_end_in_october(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $colleague = self::addMember($organization, MembershipRole::Employee);
        self::addHourlyRate($organization, $employee, '2026-01-01', 3_000);
        self::addDayRate($organization, $employee, '2026-10-31', 6_000);
        self::addHourlyRate($organization, $colleague, '2026-01-01', 9_000);
        self::mockTime(self::NOW);
        // 31 October 20:00 → 1 November 03:00 belongs wholly to October, at 31 October's rate.
        self::shift($owner, $organization, $employee, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00');
        self::shift($owner, $organization, $employee, '2026-11-02T18:00:00+01:00', '2026-11-02T20:30:00+01:00');
        self::shift($owner, $organization, $colleague, '2026-11-02T18:00:00+01:00', '2026-11-02T23:00:00+01:00');

        $november = self::myPay($employee, $organization);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'month' => '2026-11',
            'firstDate' => '2026-11-01',
            'lastDate' => '2026-11-30',
            'status' => 'estimate',
            'currency' => 'PLN',
            'shifts' => 1,
            'seconds' => 9_000,
            'paidSeconds' => 9_000,
            'shiftsWithoutRate' => 0,
            'openShifts' => 0,
            'payForHours' => 7_500,
            'adjustments' => [],
            'adjustmentsTotal' => 0,
            'total' => 7_500,
            'approvedAt' => null,
        ], $november);
        $october = self::myPay($employee, $organization, '2026-10');
        self::assertSame(['2026-10', 'estimate', 1, 25_200, 42_000], [$october['month'], $october['status'], $october['shifts'], $october['seconds'], $october['total']]);
    }

    public function test_it_shows_the_approved_paycheck_of_a_month_and_only_ones_own(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $colleague = self::addMember($organization, MembershipRole::Employee);
        self::addHourlyRate($organization, $employee, '2026-01-01', 3_000);
        self::addHourlyRate($organization, $colleague, '2026-01-01', 9_000);
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T19:10:00+02:00');
        self::shift($owner, $organization, $colleague, '2026-10-03T18:00:00+02:00', '2026-10-03T23:00:00+02:00');
        $runId = static::clientFor($owner, $organization)->request('POST', '/api/payroll-runs', ['json' => ['month' => '2026-10', 'roundingUnit' => '30', 'roundingDirection' => 'up']])->toArray()['id'];
        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/adjustments', $runId), ['json' => [
            'userId' => $employee->getId()->toRfc4122(),
            'label' => 'Advance',
            'amount' => -1_000,
        ]]);
        self::assertResponseStatusCodeSame(201);

        $draft = self::myPay($employee, $organization, '2026-10');
        self::assertSame(['estimate', 4_200, 3_500, []], [$draft['status'], $draft['paidSeconds'], $draft['total'], $draft['adjustments']], 'A draft is not shown: its month is estimated');

        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/approve', $runId));
        self::assertResponseStatusCodeSame(200);

        $approved = self::myPay($employee, $organization, '2026-10');
        self::assertSame(['approved', '2026-11-20T10:00:00+00:00', 1, 4_200, 5_400], [$approved['status'], $approved['approvedAt'], $approved['shifts'], $approved['seconds'], $approved['paidSeconds']]);
        self::assertSame([4_500, -1_000, 3_500], [$approved['payForHours'], $approved['adjustmentsTotal'], $approved['total']]);
        self::assertSame(['Advance'], array_column($approved['adjustments'], 'label'));

        // Asking for someone else's is not possible: the endpoint has no such parameter and answers with one's own.
        $asked = self::myPay($employee, $organization, '2026-10', ['userId' => $colleague->getId()->toRfc4122()]);
        self::assertSame(3_500, $asked['total']);
        self::assertSame(45_000, self::myPay($colleague, $organization, '2026-10')['total']);
        self::assertSame(['approved', 0, 0], [self::myPay($owner, $organization, '2026-10')['status'], self::myPay($owner, $organization, '2026-10')['shifts'], self::myPay($owner, $organization, '2026-10')['total']]);
    }

    /** @return iterable<string, array{MembershipRole}> */
    public static function roles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
        yield 'employee' => [MembershipRole::Employee];
    }

    #[DataProvider('roles')]
    public function test_it_shows_every_role_only_their_own_hours_and_says_when_there_is_no_rate(MembershipRole $role): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $member = MembershipRole::Owner === $role ? $owner : self::addMember($organization, $role);
        self::addHourlyRate($organization, $employee, '2026-01-01', 3_000);
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-11-02T18:00:00+01:00', '2026-11-02T23:00:00+01:00');
        self::shift($owner, $organization, $member, '2026-11-03T18:00:00+01:00', '2026-11-03T20:00:00+01:00');

        $pay = self::myPay($member, $organization);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([1, 7_200, 1, 0, 0], [$pay['shifts'], $pay['seconds'], $pay['shiftsWithoutRate'], $pay['payForHours'], $pay['total']]);
    }

    public function test_it_refuses_an_invalid_or_later_month(): void
    {
        [, $organization, $employee] = self::organizationWithEmployee();
        self::mockTime(self::NOW);

        foreach (['2026-12', '2026-13', 'november', '2026-1'] as $month) {
            self::myPay($employee, $organization, $month);
            self::assertResponseStatusCodeSame(422, $month);
        }
    }

    public function test_it_does_not_count_another_organizations_shifts_rates_or_runs(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        [$otherOwner, $other] = self::organizationWithEmployee();
        // The same person works for both organizations.
        OrganizationMother::addMember(self::reload($other), self::reloadUser($employee), MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::addHourlyRate($organization, $employee, '2026-01-01', 3_000);
        self::addHourlyRate($other, $employee, '2026-01-01', 5_000);
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        self::shift($otherOwner, $other, $employee, '2026-10-04T18:00:00+02:00', '2026-10-04T23:00:00+02:00');
        $otherRun = static::clientFor($otherOwner, $other)->request('POST', '/api/payroll-runs', ['json' => ['month' => '2026-10']])->toArray()['id'];
        static::clientFor($otherOwner, $other)->request('POST', \sprintf('/api/payroll-runs/%s/approve', $otherRun));
        self::assertResponseStatusCodeSame(200);

        $mine = self::myPay($employee, $organization, '2026-10');
        $theirs = self::myPay($employee, $other, '2026-10');

        self::assertSame(['estimate', 1, 7_200, 6_000], [$mine['status'], $mine['shifts'], $mine['seconds'], $mine['total']]);
        self::assertSame(['approved', 1, 18_000, 25_000], [$theirs['status'], $theirs['shifts'], $theirs['seconds'], $theirs['total']]);
        static::clientFor($owner, $other)->request('GET', '/api/my-pay');
        self::assertResponseStatusCodeSame(403, 'Not a member of the other organization');
    }

    /** @return array{User, Organization, User} the owner, the organization and an employee, created on 1 September */
    private static function organizationWithEmployee(): array
    {
        self::mockTime('2026-09-01 08:00:00');
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        return [$owner, $organization, self::addMember($organization, MembershipRole::Employee)];
    }

    private static function reload(Organization $organization): Organization
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.');
    }

    private static function reloadUser(User $user): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
    }

    /** Enters an ended shift for the person as an owner. */
    private static function shift(User $actor, Organization $organization, User $person, string $startedAt, string $endedAt): void
    {
        static::clientFor($actor, $organization)->request('POST', '/api/shifts', ['json' => [
            'userId' => $person->getId()->toRfc4122(),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private static function myPay(User $user, Organization $organization, ?string $month = null, array $query = []): array
    {
        if (null !== $month) {
            $query['month'] = $month;
        }
        $response = static::clientFor($user, $organization)->request('GET', '/api/my-pay', ['query' => $query]);

        /** @var array<string, mixed> */
        return $response->toArray(false);
    }
}
