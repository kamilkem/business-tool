<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Payroll\Application\Command\AddPaycheckAdjustment\AddPaycheckAdjustmentCommand;
use App\Payroll\Application\Command\AddPaycheckAdjustment\AddPaycheckAdjustmentHandler;
use App\Payroll\Application\Command\ApprovePayrollRun\ApprovePayrollRunCommand;
use App\Payroll\Application\Command\ApprovePayrollRun\ApprovePayrollRunHandler;
use App\Payroll\Application\Command\GeneratePayrollRun\GeneratePayrollRunCommand;
use App\Payroll\Application\Command\GeneratePayrollRun\GeneratePayrollRunHandler;
use App\Payroll\Application\PayrollSourcesLoader;
use App\Payroll\Application\Query\GetMyPay\GetMyPayHandler;
use App\Payroll\Application\Query\GetMyPay\GetMyPayQuery;
use App\Payroll\Application\Query\GetMyPay\MyPayView;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesHandler;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesQuery;
use App\Payroll\Domain\Exception\PayrollMonthInTheFutureException;
use App\Payroll\Domain\Model\PayEstimate;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Time\Exception\TimeException;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\PayRateMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\TimeTracking\Application\Query\ListWorkedShifts\ListWorkedShiftsQuery;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftsView;
use App\TimeTracking\Application\Query\ListWorkedShifts\WorkedShiftView;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * An organization (PLN, Europe/Warsaw) with an owner, a manager and two employees. Organization and
 * the rates answer through their real queries over in-memory repositories; Time tracking's worked
 * shifts are stubbed with $this->shifts (a shift's date is the business date it started on, as
 * Time tracking reports it). It is 20 November 2026: October has ended, November is the current month.
 */
#[CoversClass(GetMyPayHandler::class)]
#[CoversClass(MyPayView::class)]
#[CoversClass(PayEstimate::class)]
#[CoversClass(PayrollSourcesLoader::class)]
final class GetMyPayHandlerTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';

    private MockClock $clock;
    private InMemoryPayrollRunRepository $runs;
    private InMemoryHourlyRateRepository $rates;
    private InMemoryDayRateRepository $dayRates;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $colleague;
    /** @var list<WorkedShiftView> */
    private array $shifts = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->runs = new InMemoryPayrollRunRepository();
        $this->rates = new InMemoryHourlyRateRepository();
        $this->dayRates = new InMemoryDayRateRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->colleague = UserMother::any('carl@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->colleague, MembershipRole::Employee, $joined);
        $organizations->add($this->organization);

        $this->queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof ListPayRatesQuery => (new ListPayRatesHandler($this->rates, $this->dayRates))($query),
            $query instanceof ListWorkedShiftsQuery => $this->workedShifts($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });

        foreach ([$this->employee, $this->colleague] as $person) {
            $this->rates->add(PayRateMother::hourly($person->getId(), '2026-01-01', 3_000));
        }
    }

    public function test_it_estimates_this_month_from_own_ended_shifts_at_exact_time(): void
    {
        $this->work($this->employee, '2026-11-02', 3 * 3_600 + 7);
        $this->work($this->employee, '2026-11-03', 2 * 3_600);
        $this->work($this->employee, '2026-11-20', null);
        $this->work($this->colleague, '2026-11-02', 8 * 3_600);
        $this->work($this->employee, '2026-10-30', 3_600);
        // A day rate replaces the hourly rate on its date.
        $this->dayRates->add(PayRateMother::day($this->employee->getId(), '2026-11-03', 4_500));

        $pay = $this->ask($this->employee);

        self::assertSame(['2026-11', '2026-11-01', '2026-11-30', 'estimate', 'PLN'], [$pay->month, $pay->firstDate, $pay->lastDate, $pay->status, $pay->currency]);
        self::assertSame([2, 5 * 3_600 + 7, 5 * 3_600 + 7, 0, 1], [$pay->shifts, $pay->seconds, $pay->paidSeconds, $pay->shiftsWithoutRate, $pay->openShifts]);
        // (10_807 s × 3_000 + 7_200 s × 4_500) / 3_600 = 18_005.83… → 18_006: exact time, rounded once.
        self::assertSame([18_006, [], 0, 18_006, null], [$pay->payForHours, $pay->adjustments, $pay->adjustmentsTotal, $pay->total, $pay->approvedAt]);
        $asked = array_values(array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => $query instanceof ListWorkedShiftsQuery || $query instanceof ListPayRatesQuery));
        self::assertEquals([
            new ListWorkedShiftsQuery('2026-11-01', '2026-11-30', 'Europe/Warsaw', $this->employee->getId()->toRfc4122()),
            new ListPayRatesQuery('2026-11-01', '2026-11-30', $this->employee->getId()->toRfc4122()),
        ], $asked, 'Only the actor\'s shifts and rates are asked for');
    }

    public function test_it_counts_shifts_without_a_rate_and_leaves_them_out_of_the_pay(): void
    {
        $this->work($this->manager, '2026-11-02', 3_600);
        $this->work($this->employee, '2026-11-02', 3_600);
        $this->rates->add(PayRateMother::hourly($this->employee->getId(), '2026-11-03', 5_000, 'EUR'));
        $this->work($this->employee, '2026-11-04', 3_600);

        $managerPay = $this->ask($this->manager);
        self::assertSame([1, 1, 0, 0], [$managerPay->shifts, $managerPay->shiftsWithoutRate, $managerPay->payForHours, $managerPay->total]);

        // A rate in another currency than the organization's is no rate (ADR 0027 §3).
        $employeePay = $this->ask($this->employee);
        self::assertSame([2, 1, 3_000], [$employeePay->shifts, $employeePay->shiftsWithoutRate, $employeePay->payForHours]);
    }

    public function test_it_counts_a_shift_across_the_end_of_a_month_wholly_in_the_month_it_started(): void
    {
        // 31 October 20:00 → 1 November 03:00 is October's (ADR 0027 §5), at 31 October's rate.
        $this->work($this->employee, '2026-10-31', 7 * 3_600);
        $this->dayRates->add(PayRateMother::day($this->employee->getId(), '2026-10-31', 6_000));
        $this->rates->add(PayRateMother::hourly($this->employee->getId(), '2026-11-01', 9_000));
        $this->work($this->employee, '2026-11-01', 3_600);

        $october = $this->ask($this->employee, '2026-10');
        $november = $this->ask($this->employee, '2026-11');

        self::assertSame([1, 7 * 3_600, 42_000], [$october->shifts, $october->seconds, $october->total]);
        self::assertSame([1, 3_600, 9_000], [$november->shifts, $november->seconds, $november->total]);
    }

    public function test_it_shows_the_approved_paycheck_with_its_rounding_and_adjustments(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600 + 600);
        $this->work($this->colleague, '2026-10-03', 3_600);
        $runId = $this->generate('2026-10', '30', 'up');
        $this->adjust($runId, $this->employee, 'Bonus', 1_000);
        $this->approve($runId);

        $pay = $this->ask($this->employee, '2026-10');

        self::assertSame(['2026-10', 'approved', 'PLN', '2026-11-20T10:00:00+00:00'], [$pay->month, $pay->status, $pay->currency, $pay->approvedAt?->format(\DATE_ATOM)]);
        self::assertSame([1, 4_200, 5_400, 0, 0], [$pay->shifts, $pay->seconds, $pay->paidSeconds, $pay->shiftsWithoutRate, $pay->openShifts]);
        self::assertSame([4_500, 1_000, 5_500], [$pay->payForHours, $pay->adjustmentsTotal, $pay->total]);
        self::assertSame(['Bonus'], array_map(static fn ($adjustment): string => $adjustment->label, $pay->adjustments));
    }

    public function test_it_estimates_a_month_whose_run_is_a_draft_without_its_rounding_or_adjustments(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600 + 600);
        $runId = $this->generate('2026-10', '30', 'up');
        $this->adjust($runId, $this->employee, 'Bonus', 1_000);

        $pay = $this->ask($this->employee, '2026-10');

        self::assertSame(['estimate', 4_200, 3_500, [], 3_500, null], [$pay->status, $pay->paidSeconds, $pay->payForHours, $pay->adjustments, $pay->total, $pay->approvedAt]);
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_shows_owners_and_managers_only_their_own_approved_pay(string $actor): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $this->approve($this->generate('2026-10'));
        $user = 'owner' === $actor ? $this->owner : $this->manager;

        $pay = $this->ask($user, '2026-10');

        self::assertSame(['approved', 0, 0, 0, []], [$pay->status, $pay->shifts, $pay->seconds, $pay->total, $pay->adjustments], 'No paycheck of theirs on the run');
    }

    public function test_it_refuses_a_later_month_or_an_invalid_one_and_shows_nothing_to_a_non_member(): void
    {
        $this->assertThrows(PayrollMonthInTheFutureException::class, fn () => $this->ask($this->employee, '2026-12'));
        $this->assertThrows(TimeException::class, fn () => $this->ask($this->employee, '2026-13'));
        self::assertNull(($this->handler())(new GetMyPayQuery($this->organization->getId()->toRfc4122(), Uuid::v7()->toRfc4122())));
        OrganizationMother::deactivateMember($this->organization, $this->colleague, new \DateTimeImmutable(self::NOW));
        self::assertNull(($this->handler())(new GetMyPayQuery($this->organization->getId()->toRfc4122(), $this->colleague->getId()->toRfc4122())));
    }

    private function ask(User $user, ?string $month = null): MyPayView
    {
        $view = ($this->handler())(new GetMyPayQuery($this->organization->getId()->toRfc4122(), $user->getId()->toRfc4122(), $month));
        self::assertNotNull($view);

        return $view;
    }

    private function handler(): GetMyPayHandler
    {
        return new GetMyPayHandler($this->runs, new PayrollSourcesLoader($this->queryBus), $this->queryBus, $this->clock);
    }

    private function generate(string $month, string $unit = 'none', string $direction = 'nearest'): string
    {
        $runId = Uuid::v7()->toRfc4122();
        (new GeneratePayrollRunHandler($this->runs, new PayrollSourcesLoader($this->queryBus), $this->queryBus, $this->clock))(
            new GeneratePayrollRunCommand($this->organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122(), $runId, $month, $unit, $direction),
        );

        return $runId;
    }

    private function adjust(string $runId, User $user, string $label, int $amount): void
    {
        (new AddPaycheckAdjustmentHandler($this->runs, $this->queryBus, $this->clock))(new AddPaycheckAdjustmentCommand(
            $this->organization->getId()->toRfc4122(),
            $this->owner->getId()->toRfc4122(),
            $runId,
            Uuid::v7()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $label,
            $amount,
        ));
    }

    private function approve(string $runId): void
    {
        (new ApprovePayrollRunHandler($this->runs, new PayrollSourcesLoader($this->queryBus), $this->queryBus, new RecordingEventPublisher(), $this->clock))(
            new ApprovePayrollRunCommand($this->organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122(), $runId),
        );
    }

    private function work(User $user, string $date, ?int $seconds): void
    {
        $startedAt = new \DateTimeImmutable($date.' 18:00:00', new \DateTimeZone('UTC'));
        $this->shifts[] = new WorkedShiftView(
            Uuid::v7()->toRfc4122(),
            $user->getId()->toRfc4122(),
            $date,
            $startedAt,
            null === $seconds ? null : $startedAt->modify(\sprintf('+%d seconds', $seconds)),
            $seconds,
        );
    }

    private function workedShifts(ListWorkedShiftsQuery $query): WorkedShiftsView
    {
        return new WorkedShiftsView(
            array_values(array_filter(
                $this->shifts,
                static fn (WorkedShiftView $shift): bool => $shift->date >= $query->from && $shift->date <= $query->to && (null === $query->userId || $shift->userId === $query->userId),
            )),
            0,
        );
    }

    /**
     * @param class-string<\Throwable> $exception
     * @param \Closure(): mixed        $action
     */
    private function assertThrows(string $exception, \Closure $action): void
    {
        try {
            $action();
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);

            return;
        }
        self::fail(\sprintf('%s expected.', $exception));
    }
}
