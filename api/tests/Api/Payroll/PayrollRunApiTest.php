<?php

declare(strict_types=1);

namespace App\Tests\Api\Payroll;

use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Tests\Fixtures\OrganizationMother;
use App\User\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * /payroll-runs (#62, ADR 0027 §4–10). Organizations are in PLN and Europe/Warsaw, created on
 * 1 September 2026; the server's time is then mocked at NOW (20 November 2026, UTC), so October has
 * ended and November is the current month.
 */
final class PayrollRunApiTest extends PayrollApiTestCase
{
    public function test_it_generates_reviews_adjusts_approves_and_reopens_a_month(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $manager = self::addMember($organization, MembershipRole::Manager);
        self::addHourlyRate($organization, $employee, '2026-01-01', 3_000);
        self::addHourlyRate($organization, $employee, '2026-10-15', 3_600);
        self::mockTime(self::NOW);
        $early = self::shift($manager, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T22:08:00+02:00');
        // A night from the last day of October into November belongs wholly to October, at October 31st's rate.
        $night = self::shift($manager, $organization, $employee, '2026-10-31T20:00:00+01:00', '2026-11-01T03:00:00+01:00');
        self::shift($manager, $organization, $employee, '2026-11-01T20:00:00+01:00', '2026-11-01T23:00:00+01:00');

        $response = self::generate($manager, $organization, ['month' => '2026-10', 'roundingUnit' => '15', 'roundingDirection' => 'nearest']);

        self::assertResponseStatusCodeSame(201);
        $run = $response->toArray();
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($run['id']));
        $paycheck = $run['paychecks'][0];
        unset($run['paychecks']);
        self::assertSame([
            'id' => $run['id'],
            'month' => '2026-10',
            'firstDate' => '2026-10-01',
            'lastDate' => '2026-10-31',
            'status' => 'draft',
            'currency' => 'PLN',
            'roundingUnit' => '15',
            'roundingDirection' => 'nearest',
            'openShifts' => 0,
            'pendingChangeRequests' => 0,
            'linesWithoutRate' => 0,
            'total' => 37_950,
            'createdBy' => $manager->getId()->toRfc4122(),
            'createdAt' => '2026-11-20T10:00:00+00:00',
            'generatedBy' => $manager->getId()->toRfc4122(),
            'generatedAt' => '2026-11-20T10:00:00+00:00',
            'approvedBy' => null,
            'approvedAt' => null,
        ], $run);
        self::assertSame($employee->getId()->toRfc4122(), $paycheck['userId']);
        self::assertSame([$early, $night], array_column($paycheck['lines'], 'shiftId'));
        self::assertSame(['2026-10-03', '2026-10-31'], array_column($paycheck['lines'], 'date'));
        self::assertSame([14_880, 25_200], array_column($paycheck['lines'], 'seconds'));
        self::assertSame([15_300, 25_200], array_column($paycheck['lines'], 'paidSeconds'));
        self::assertSame([3_000, 3_600], array_column($paycheck['lines'], 'rate'));
        self::assertSame(['hourly', 'hourly'], array_column($paycheck['lines'], 'rateKind'));
        self::assertSame([12_750, 25_200], array_column($paycheck['lines'], 'amount'));
        self::assertSame([40_500, 37_950, 0, 37_950, 0], [$paycheck['paidSeconds'], $paycheck['payForHours'], $paycheck['adjustmentsTotal'], $paycheck['total'], $paycheck['linesWithoutRate']]);

        // An advance already paid comes off the month.
        $adjusted = self::adjust($manager, $organization, $run['id'], ['userId' => $employee->getId()->toRfc4122(), 'label' => 'Advance', 'amount' => -10_000]);
        self::assertResponseStatusCodeSame(201);
        $advance = $adjusted->toArray()['paychecks'][0]['adjustments'][0];
        self::assertSame(['Advance', -10_000], [$advance['label'], $advance['amount']]);
        static::clientFor($manager, $organization)->request('PATCH', \sprintf('/api/payroll-runs/%s/adjustments/%s', $run['id'], $advance['id']), [
            'json' => ['amount' => -7_950],
            'headers' => self::MERGE_PATCH,
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(30_000, self::get($owner, $organization, $run['id'])->toArray()['total']);

        // The employee sees nothing until the owner approves.
        self::get($employee, $organization, $run['id']);
        self::assertResponseStatusCodeSame(404);

        $approved = self::action($owner, $organization, $run['id'], 'approve');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['approved', $owner->getId()->toRfc4122(), '2026-11-20T10:00:00+00:00'], [$approved->toArray()['status'], $approved->toArray()['approvedBy'], $approved->toArray()['approvedAt']]);

        // The month's shifts and rates are locked; November is not.
        self::enter($manager, $organization, $employee, '2026-10-20T18:00:00+02:00', '2026-10-20T20:00:00+02:00');
        self::assertResponseStatusCodeSame(409);
        self::addRate($owner, $organization, $employee, '2026-10-20');
        self::assertResponseStatusCodeSame(409);
        self::enter($manager, $organization, $employee, '2026-11-02T18:00:00+01:00', '2026-11-02T20:00:00+01:00');
        self::assertResponseStatusCodeSame(201);

        $own = self::get($employee, $organization, $run['id'])->toArray();
        self::assertSame([30_000, 1], [$own['total'], \count($own['paychecks'])]);
        self::assertSame(['2026-10'], array_column(self::list($employee, $organization), 'month'));

        self::action($owner, $organization, $run['id'], 'reopen');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('draft', self::get($owner, $organization, $run['id'])->toArray()['status']);
        self::enter($manager, $organization, $employee, '2026-10-20T18:00:00+02:00', '2026-10-20T20:00:00+02:00');
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], self::list($employee, $organization));

        // The new shift makes the draft stale until it is regenerated.
        $stale = self::action($owner, $organization, $run['id'], 'approve');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('regenerate it', self::detail($stale));
        $regenerated = static::clientFor($manager, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/regenerate', $run['id']), ['json' => ['roundingUnit' => 'none', 'roundingDirection' => 'nearest']]);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(3, $regenerated->toArray()['paychecks'][0]['lines']);
        self::assertSame(-7_950, $regenerated->toArray()['paychecks'][0]['adjustmentsTotal']);
        self::action($owner, $organization, $run['id'], 'approve');
        self::assertResponseStatusCodeSame(200);
    }

    public function test_it_shows_members_with_shifts_but_no_rate_and_refuses_to_approve_them(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $former = self::addMember($organization, MembershipRole::Employee);
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        self::shift($owner, $organization, $former, '2026-10-04T18:00:00+02:00', '2026-10-04T20:00:00+02:00');
        OrganizationMother::deactivateMember(self::reload($organization), self::reloadUser($former));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::addHourlyRate($organization, $former, '2026-01-01', 2_500);

        $run = self::generate($owner, $organization, ['month' => '2026-10'])->toArray();

        self::assertSame(1, $run['linesWithoutRate']);
        $byUser = array_column($run['paychecks'], null, 'userId');
        self::assertSame(1, $byUser[$employee->getId()->toRfc4122()]['linesWithoutRate']);
        self::assertNull($byUser[$employee->getId()->toRfc4122()]['lines'][0]['rate']);
        self::assertNull($byUser[$employee->getId()->toRfc4122()]['lines'][0]['amount']);
        self::assertSame(5_000, $byUser[$former->getId()->toRfc4122()]['total'], 'A deactivated member is paid for their shifts');

        $response = self::action($owner, $organization, $run['id'], 'approve');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('have no rate', self::detail($response));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidGenerations(): iterable
    {
        yield 'no month' => [[]];
        yield 'not a month' => [['month' => '2026-13']];
        yield 'a date' => [['month' => '2026-10-01']];
        yield 'unknown unit' => [['month' => '2026-10', 'roundingUnit' => '45']];
        yield 'unknown direction' => [['month' => '2026-10', 'roundingDirection' => 'sideways']];
        yield 'a later month' => [['month' => '2026-12']];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidGenerations')]
    public function test_it_refuses_an_invalid_generation(array $body): void
    {
        [$owner, $organization] = self::organizationWithEmployee();
        self::mockTime(self::NOW);

        self::generate($owner, $organization, $body);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_refuses_invalid_adjustments_and_rounding(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        $runId = self::generate($owner, $organization, ['month' => '2026-10'])->toArray()['id'];

        foreach ([
            ['userId' => $employee->getId()->toRfc4122(), 'label' => ' ', 'amount' => 100],
            ['userId' => $employee->getId()->toRfc4122(), 'label' => 'Bonus', 'amount' => 0],
            ['userId' => 'nope', 'label' => 'Bonus', 'amount' => 100],
            ['userId' => $employee->getId()->toRfc4122(), 'label' => 'Bonus'],
        ] as $body) {
            self::adjust($owner, $organization, $runId, $body);
            self::assertResponseStatusCodeSame(422);
        }

        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/regenerate', $runId), ['json' => ['roundingUnit' => '60']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function test_it_answers_404_for_what_is_not_there(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $stranger = self::addMember(self::otherOrganization(), MembershipRole::Employee);
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        $runId = self::generate($owner, $organization, ['month' => '2026-10'])->toArray()['id'];
        $unknown = Uuid::v7()->toRfc4122();

        self::get($owner, $organization, $unknown);
        self::assertResponseStatusCodeSame(404);
        self::action($owner, $organization, $unknown, 'approve');
        self::assertResponseStatusCodeSame(404);
        self::adjust($owner, $organization, $runId, ['userId' => $stranger->getId()->toRfc4122(), 'label' => 'Bonus', 'amount' => 100]);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('PATCH', \sprintf('/api/payroll-runs/%s/adjustments/%s', $runId, $unknown), ['json' => ['amount' => 1], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('DELETE', \sprintf('/api/payroll-runs/%s/adjustments/%s', $runId, $unknown));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_it_answers_409_for_the_wrong_state(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        self::addHourlyRate($organization, $employee, '2026-01-01');
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        self::shift($owner, $organization, $employee, '2026-11-03T18:00:00+01:00', '2026-11-03T20:00:00+01:00');

        // Nothing to pay in September.
        self::assertStringContainsString('Nothing to pay', self::detail(self::generate($owner, $organization, ['month' => '2026-09'])));
        self::assertResponseStatusCodeSame(409);

        // November is the current month: a preview that cannot be approved.
        $november = self::generate($owner, $organization, ['month' => '2026-11'])->toArray()['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('has not ended', self::detail(self::action($owner, $organization, $november, 'approve')));
        self::assertResponseStatusCodeSame(409);

        $october = self::generate($owner, $organization, ['month' => '2026-10'])->toArray()['id'];
        self::generate($owner, $organization, ['month' => '2026-10']);
        self::assertResponseStatusCodeSame(409);

        self::action($owner, $organization, $october, 'reopen');
        self::assertResponseStatusCodeSame(409);
        self::action($owner, $organization, $october, 'approve');
        self::assertResponseStatusCodeSame(200);
        self::action($owner, $organization, $october, 'approve');
        self::assertResponseStatusCodeSame(409);
        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/regenerate', $october), ['json' => []]);
        self::assertResponseStatusCodeSame(409);
        static::clientFor($owner, $organization)->request('DELETE', '/api/payroll-runs/'.$october);
        self::assertResponseStatusCodeSame(409);
        self::adjust($owner, $organization, $october, ['userId' => $employee->getId()->toRfc4122(), 'label' => 'Bonus', 'amount' => 100]);
        self::assertResponseStatusCodeSame(409);

        // An open shift of the month blocks approval.
        self::mockTime('2026-11-30 18:00:00');
        static::clientFor($employee, $organization)->request('POST', '/api/shifts/clock-in');
        self::assertResponseStatusCodeSame(201);
        self::mockTime('2026-12-02 10:00:00');
        static::clientFor($owner, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/regenerate', $november), ['json' => []]);
        self::assertSame(1, self::get($owner, $organization, $november)->toArray()['openShifts']);
        self::assertStringContainsString('still open', self::detail(self::action($owner, $organization, $november, 'approve')));
        self::assertResponseStatusCodeSame(409);
    }

    /** @return iterable<string, array{MembershipRole, int, int, int}> role, then the status of generating, approving and reopening */
    public static function roles(): iterable
    {
        yield 'owner' => [MembershipRole::Owner, 201, 200, 200];
        yield 'manager' => [MembershipRole::Manager, 201, 403, 403];
        yield 'employee' => [MembershipRole::Employee, 403, 403, 403];
    }

    #[DataProvider('roles')]
    public function test_it_lets_owners_and_managers_prepare_runs_and_only_owners_approve_and_reopen(MembershipRole $role, int $generate, int $approve, int $reopen): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        $actor = MembershipRole::Owner === $role ? $owner : self::addMember($organization, $role);
        self::addHourlyRate($organization, $employee, '2026-01-01');
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-09-03T18:00:00+02:00', '2026-09-03T20:00:00+02:00');
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        $runId = self::generate($owner, $organization, ['month' => '2026-10'])->toArray()['id'];
        $preparing = MembershipRole::Employee === $role ? 403 : 200;

        self::generate($actor, $organization, ['month' => '2026-09']);
        self::assertResponseStatusCodeSame($generate);

        self::get($actor, $organization, $runId);
        self::assertResponseStatusCodeSame(MembershipRole::Employee === $role ? 404 : 200);
        self::assertCount(MembershipRole::Employee === $role ? 0 : (201 === $generate ? 2 : 1), self::list($actor, $organization));

        static::clientFor($actor, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/regenerate', $runId), ['json' => []]);
        self::assertResponseStatusCodeSame($preparing);

        $added = self::adjust($actor, $organization, $runId, ['userId' => $employee->getId()->toRfc4122(), 'label' => 'Bonus', 'amount' => 100]);
        self::assertResponseStatusCodeSame(MembershipRole::Employee === $role ? 403 : 201);
        $adjustmentId = MembershipRole::Employee === $role
            ? self::adjust($owner, $organization, $runId, ['userId' => $employee->getId()->toRfc4122(), 'label' => 'Bonus', 'amount' => 100])->toArray()['paychecks'][0]['adjustments'][0]['id']
            : $added->toArray()['paychecks'][0]['adjustments'][0]['id'];

        // An employee does not see the draft, so changing its adjustment is a 404 before it is a 403.
        static::clientFor($actor, $organization)->request('PATCH', \sprintf('/api/payroll-runs/%s/adjustments/%s', $runId, $adjustmentId), ['json' => ['label' => 'Bonus!'], 'headers' => self::MERGE_PATCH]);
        self::assertResponseStatusCodeSame(MembershipRole::Employee === $role ? 404 : 200);
        static::clientFor($actor, $organization)->request('DELETE', \sprintf('/api/payroll-runs/%s/adjustments/%s', $runId, $adjustmentId));
        self::assertResponseStatusCodeSame(MembershipRole::Employee === $role ? 403 : 204);

        self::action($actor, $organization, $runId, 'approve');
        self::assertResponseStatusCodeSame($approve);
        if (200 !== $approve) {
            self::action($owner, $organization, $runId, 'approve');
        }
        self::action($actor, $organization, $runId, 'reopen');
        self::assertResponseStatusCodeSame($reopen);
        if (200 !== $reopen) {
            self::action($owner, $organization, $runId, 'reopen');
        }

        static::clientFor($actor, $organization)->request('DELETE', '/api/payroll-runs/'.$runId);
        self::assertResponseStatusCodeSame(MembershipRole::Employee === $role ? 403 : 204);
    }

    public function test_it_does_not_show_or_count_another_organizations_runs_and_shifts(): void
    {
        [$owner, $organization, $employee] = self::organizationWithEmployee();
        [$otherOwner, $other, $otherEmployee] = self::organizationWithEmployee();
        OrganizationMother::addMember(self::reload($other), self::reloadUser($employee), MembershipRole::Employee);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        self::mockTime(self::NOW);
        self::shift($owner, $organization, $employee, '2026-10-03T18:00:00+02:00', '2026-10-03T20:00:00+02:00');
        // The same person works for the other organization too: those shifts are not this one's.
        self::shift($otherOwner, $other, $employee, '2026-10-04T18:00:00+02:00', '2026-10-04T23:00:00+02:00');
        self::shift($otherOwner, $other, $otherEmployee, '2026-10-05T18:00:00+02:00', '2026-10-05T23:00:00+02:00');
        $otherRun = self::generate($otherOwner, $other, ['month' => '2026-10'])->toArray()['id'];

        $mine = self::generate($owner, $organization, ['month' => '2026-10']);

        self::assertResponseStatusCodeSame(201, 'One run per month and organization');
        self::assertSame([7_200], array_column($mine->toArray()['paychecks'], 'paidSeconds'));
        self::assertSame([$mine->toArray()['id']], array_column(self::list($owner, $organization), 'id'));
        self::get($owner, $organization, $otherRun);
        self::assertResponseStatusCodeSame(404);
        self::action($owner, $organization, $otherRun, 'approve');
        self::assertResponseStatusCodeSame(404);
        static::clientFor($owner, $organization)->request('DELETE', '/api/payroll-runs/'.$otherRun);
        self::assertResponseStatusCodeSame(404);
        self::get($owner, $other, $otherRun);
        self::assertResponseStatusCodeSame(403, 'Not a member of the other organization');
    }

    /** @return array{User, Organization, User} the owner, the organization and an employee, created on 1 September */
    private static function organizationWithEmployee(): array
    {
        self::mockTime('2026-09-01 08:00:00');
        [$owner, $organization] = self::memberWithRole(MembershipRole::Owner);

        return [$owner, $organization, self::addMember($organization, MembershipRole::Employee)];
    }

    private static function otherOrganization(): Organization
    {
        return self::memberWithRole(MembershipRole::Owner)[1];
    }

    private static function reload(Organization $organization): Organization
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(Organization::class, $organization->getId()) ?? throw new \LogicException('Organization expected.');
    }

    private static function reloadUser(User $user): User
    {
        return static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $user->getId()) ?? throw new \LogicException('User expected.');
    }

    /** Enters an ended shift for the person as an owner or manager; returns its id. */
    private static function shift(User $actor, Organization $organization, User $person, string $startedAt, string $endedAt): string
    {
        $response = self::enter($actor, $organization, $person, $startedAt, $endedAt);
        self::assertResponseStatusCodeSame(201);

        return $response->toArray()['id'];
    }

    private static function enter(User $actor, Organization $organization, User $person, string $startedAt, string $endedAt): ResponseInterface
    {
        return static::clientFor($actor, $organization)->request('POST', '/api/shifts', ['json' => [
            'userId' => $person->getId()->toRfc4122(),
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
        ]]);
    }

    private static function addRate(User $actor, Organization $organization, User $person, string $effectiveFrom): void
    {
        static::clientFor($actor, $organization)->request('POST', '/api/hourly-rates', ['json' => [
            'userId' => $person->getId()->toRfc4122(),
            'effectiveFrom' => $effectiveFrom,
            'amount' => 5_000,
        ]]);
    }

    /** @param array<string, mixed> $body */
    private static function generate(User $user, Organization $organization, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', '/api/payroll-runs', ['json' => $body]);
    }

    private static function get(User $user, Organization $organization, string $runId): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('GET', '/api/payroll-runs/'.$runId);
    }

    /** @return list<array<string, mixed>> */
    private static function list(User $user, Organization $organization): array
    {
        /** @var list<array<string, mixed>> */
        return static::clientFor($user, $organization)->request('GET', '/api/payroll-runs')->toArray();
    }

    private static function action(User $user, Organization $organization, string $runId, string $action): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/%s', $runId, $action));
    }

    /** @param array<string, mixed> $body */
    private static function adjust(User $user, Organization $organization, string $runId, array $body): ResponseInterface
    {
        return static::clientFor($user, $organization)->request('POST', \sprintf('/api/payroll-runs/%s/adjustments', $runId), ['json' => $body]);
    }

    private static function detail(ResponseInterface $response): string
    {
        $body = $response->toArray(false);
        self::assertIsString($body['detail'] ?? null);

        return $body['detail'];
    }
}
