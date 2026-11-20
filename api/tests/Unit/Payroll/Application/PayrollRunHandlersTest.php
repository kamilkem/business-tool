<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payroll\Application;

use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\MemberNotFoundException;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Payroll\Application\Command\AddPaycheckAdjustment\AddPaycheckAdjustmentCommand;
use App\Payroll\Application\Command\AddPaycheckAdjustment\AddPaycheckAdjustmentHandler;
use App\Payroll\Application\Command\ApprovePayrollRun\ApprovePayrollRunCommand;
use App\Payroll\Application\Command\ApprovePayrollRun\ApprovePayrollRunHandler;
use App\Payroll\Application\Command\ChangePaycheckAdjustment\ChangePaycheckAdjustmentCommand;
use App\Payroll\Application\Command\ChangePaycheckAdjustment\ChangePaycheckAdjustmentHandler;
use App\Payroll\Application\Command\DeletePayrollRun\DeletePayrollRunCommand;
use App\Payroll\Application\Command\DeletePayrollRun\DeletePayrollRunHandler;
use App\Payroll\Application\Command\GeneratePayrollRun\GeneratePayrollRunCommand;
use App\Payroll\Application\Command\GeneratePayrollRun\GeneratePayrollRunHandler;
use App\Payroll\Application\Command\MarkPaycheckPaid\MarkPaycheckPaidCommand;
use App\Payroll\Application\Command\MarkPaycheckPaid\MarkPaycheckPaidHandler;
use App\Payroll\Application\Command\RegeneratePayrollRun\RegeneratePayrollRunCommand;
use App\Payroll\Application\Command\RegeneratePayrollRun\RegeneratePayrollRunHandler;
use App\Payroll\Application\Command\RemovePaycheckAdjustment\RemovePaycheckAdjustmentCommand;
use App\Payroll\Application\Command\RemovePaycheckAdjustment\RemovePaycheckAdjustmentHandler;
use App\Payroll\Application\Command\ReopenPayrollRun\ReopenPayrollRunCommand;
use App\Payroll\Application\Command\ReopenPayrollRun\ReopenPayrollRunHandler;
use App\Payroll\Application\Command\UndoPaycheckPaid\UndoPaycheckPaidCommand;
use App\Payroll\Application\Command\UndoPaycheckPaid\UndoPaycheckPaidHandler;
use App\Payroll\Application\PayrollActor;
use App\Payroll\Application\PayrollSourcesLoader;
use App\Payroll\Application\Query\GetPayrollRun\GetPayrollRunHandler;
use App\Payroll\Application\Query\GetPayrollRun\GetPayrollRunQuery;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckAdjustmentView;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckLineView;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckView;
use App\Payroll\Application\Query\GetPayrollRun\PayrollRunView;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesHandler;
use App\Payroll\Application\Query\ListPayRates\ListPayRatesQuery;
use App\Payroll\Application\Query\ListPayrollRuns\ListPayrollRunsHandler;
use App\Payroll\Application\Query\ListPayrollRuns\ListPayrollRunsQuery;
use App\Payroll\Application\Query\ListPayrollRuns\PayrollRunSummaryView;
use App\Payroll\Application\Query\PayrollRunPayment;
use App\Payroll\Application\Query\PayrollRunVisibility;
use App\Payroll\Domain\Event\PayrollRunApproved;
use App\Payroll\Domain\Event\PayrollRunReopened;
use App\Payroll\Domain\Exception\InvalidRoundingException;
use App\Payroll\Domain\Exception\PaycheckAdjustmentNotFoundException;
use App\Payroll\Domain\Exception\PaycheckNotFoundException;
use App\Payroll\Domain\Exception\PaycheckPaymentException;
use App\Payroll\Domain\Exception\PaymentDateInTheFutureException;
use App\Payroll\Domain\Exception\PayrollAccessForbiddenException;
use App\Payroll\Domain\Exception\PayrollRunEmptyException;
use App\Payroll\Domain\Exception\PayrollRunMonthTakenException;
use App\Payroll\Domain\Exception\PayrollRunNotApprovableException;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Payroll\Domain\Exception\PayrollRunStatusException;
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
 * An organization (PLN, Europe/Warsaw) with an owner, a manager, two employees and a deactivated
 * employee. Organization and the rates answer through their real queries over in-memory repositories;
 * Time tracking's worked shifts are stubbed with $this->shifts. It is 20 November 2026.
 */
#[CoversClass(GeneratePayrollRunHandler::class)]
#[CoversClass(RegeneratePayrollRunHandler::class)]
#[CoversClass(DeletePayrollRunHandler::class)]
#[CoversClass(ApprovePayrollRunHandler::class)]
#[CoversClass(ReopenPayrollRunHandler::class)]
#[CoversClass(AddPaycheckAdjustmentHandler::class)]
#[CoversClass(ChangePaycheckAdjustmentHandler::class)]
#[CoversClass(RemovePaycheckAdjustmentHandler::class)]
#[CoversClass(MarkPaycheckPaidHandler::class)]
#[CoversClass(UndoPaycheckPaidHandler::class)]
#[CoversClass(PayrollRunPayment::class)]
#[CoversClass(GetPayrollRunHandler::class)]
#[CoversClass(ListPayrollRunsHandler::class)]
#[CoversClass(PayrollRunVisibility::class)]
#[CoversClass(PayrollRunView::class)]
#[CoversClass(PaycheckView::class)]
#[CoversClass(PaycheckLineView::class)]
#[CoversClass(PaycheckAdjustmentView::class)]
#[CoversClass(PayrollRunSummaryView::class)]
#[CoversClass(PayrollSourcesLoader::class)]
#[CoversClass(PayrollActor::class)]
final class PayrollRunHandlersTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';
    private const string JOINED = '2026-01-05 09:00:00';

    private MockClock $clock;
    private InMemoryPayrollRunRepository $runs;
    private InMemoryHourlyRateRepository $rates;
    private RecordingEventPublisher $events;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $colleague;
    private User $former;
    private User $outsider;
    /** @var list<WorkedShiftView> */
    private array $shifts = [];
    private int $pending = 0;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->runs = new InMemoryPayrollRunRepository();
        $this->rates = new InMemoryHourlyRateRepository();
        $dayRates = new InMemoryDayRateRepository();
        $this->events = new RecordingEventPublisher();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = UserMother::any('mark@example.com');
        $this->employee = UserMother::any('eve@example.com');
        $this->colleague = UserMother::any('carl@example.com');
        $this->former = UserMother::any('fred@example.com');
        $this->outsider = UserMother::any('oscar@example.com');
        $joined = new \DateTimeImmutable(self::JOINED);
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->colleague, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->former, MembershipRole::Employee, $joined);
        OrganizationMother::deactivateMember($this->organization, $this->former, $joined->modify('+10 months'));
        $organizations->add($this->organization);

        $rates = $this->rates;
        $this->queryBus = new StubQueryBus(fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof ListPayRatesQuery => (new ListPayRatesHandler($rates, $dayRates))($query),
            $query instanceof ListWorkedShiftsQuery => $this->workedShifts($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
        });

        foreach ([$this->employee, $this->colleague, $this->former] as $person) {
            $this->rates->add(PayRateMother::hourly($person->getId(), '2026-01-01', 3_000));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function managers(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'manager' => ['manager'];
    }

    #[DataProvider('managers')]
    public function test_it_generates_a_draft_with_a_paycheck_per_member_with_shifts(string $actor): void
    {
        $this->work($this->employee, '2026-10-03', 7_200);
        $this->work($this->former, '2026-10-04', 3_600);
        $this->work($this->colleague, '2026-11-01', 3_600);

        $runId = $this->generate($actor, '2026-10', '15', 'up');

        $run = $this->shown($runId, 'manager');
        self::assertSame('2026-10', $run->month);
        self::assertSame('2026-10-01', $run->firstDate);
        self::assertSame('2026-10-31', $run->lastDate);
        self::assertSame('draft', $run->status);
        self::assertSame('PLN', $run->currency);
        self::assertSame(['15', 'up'], [$run->roundingUnit, $run->roundingDirection]);
        self::assertSame(['employee' => 6_000, 'former' => 3_000], $this->totals($run));
        self::assertSame($this->id($actor), $run->createdBy);
        $query = array_values(array_filter($this->queryBus->queries, static fn (QueryInterface $query): bool => $query instanceof ListWorkedShiftsQuery))[0];
        self::assertEquals(new ListWorkedShiftsQuery('2026-10-01', '2026-10-31', 'Europe/Warsaw'), $query);
    }

    public function test_it_shows_a_member_with_shifts_but_no_rate(): void
    {
        $this->work($this->manager, '2026-10-03', 3_600);

        $run = $this->shown($this->generate('owner'), 'owner');

        $paycheck = $run->paychecks[0];
        self::assertSame($this->manager->getId()->toRfc4122(), $paycheck->userId);
        self::assertSame(1, $paycheck->linesWithoutRate);
        self::assertSame(1, $run->linesWithoutRate);
        self::assertNull($paycheck->lines[0]->rate);
        self::assertNull($paycheck->lines[0]->amount);
        self::assertSame(0, $paycheck->total);
    }

    public function test_it_refuses_an_employee_or_a_non_member(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);

        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $this->generate('employee'));
        $this->assertThrows(OrganizationNotFoundException::class, fn () => $this->generate('outsider'));
        self::assertSame([], $this->runs->findAll());
    }

    public function test_it_refuses_a_second_run_of_a_month_an_invalid_month_or_rounding_and_an_empty_month(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $this->generate('owner');

        $this->assertThrows(PayrollRunMonthTakenException::class, fn () => $this->generate('owner'));
        $this->assertThrows(TimeException::class, fn () => $this->generate('owner', '2026-13'));
        $this->assertThrows(InvalidRoundingException::class, fn () => $this->generate('owner', '2026-09', '45'));
        $this->assertThrows(PayrollRunEmptyException::class, fn () => $this->generate('owner', '2026-09'));
    }

    public function test_it_regenerates_a_draft_with_the_current_shifts_and_new_rounding(): void
    {
        $this->work($this->employee, '2026-10-03', 3_000);
        $runId = $this->generate('owner');
        $this->work($this->colleague, '2026-10-04', 3_600);
        $this->clock->modify('+1 hour');

        ($this->regenerateHandler())(new RegeneratePayrollRunCommand($this->orgId(), $this->id('manager'), $runId, '60', 'up'));

        $run = $this->shown($runId, 'owner');
        self::assertSame(['employee' => 3_000, 'colleague' => 3_000], $this->totals($run));
        self::assertSame($this->id('manager'), $run->generatedBy);
        self::assertEquals($this->clock->now(), $run->generatedAt);

        $this->assertThrows(InvalidRoundingException::class, fn () => ($this->regenerateHandler())(new RegeneratePayrollRunCommand($this->orgId(), $this->id('manager'), $runId, '60')));
        $this->assertThrows(PayrollRunNotFoundException::class, fn () => ($this->regenerateHandler())(new RegeneratePayrollRunCommand($this->orgId(), $this->id('manager'), Uuid::v7()->toRfc4122())));
        $this->assertThrows(PayrollRunNotFoundException::class, fn () => ($this->regenerateHandler())(new RegeneratePayrollRunCommand($this->orgId(), $this->id('manager'), 'not-a-uuid')));
    }

    public function test_it_approves_as_an_owner_and_publishes_the_event_once(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('manager');

        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $this->approve('manager', $runId));
        $this->approve('owner', $runId);

        $run = $this->shown($runId, 'owner');
        self::assertSame('approved', $run->status);
        self::assertSame($this->id('owner'), $run->approvedBy);
        self::assertEquals([new PayrollRunApproved($this->orgId(), $runId, '2026-10-01', '2026-10-31', $this->id('owner'))], $this->events->events);

        $this->assertThrows(PayrollRunStatusException::class, fn () => $this->approve('owner', $runId));
        self::assertCount(1, $this->events->events);
    }

    public function test_it_refuses_to_approve_a_run_that_is_no_longer_current(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('owner');
        $this->rates->add(PayRateMother::hourly($this->employee->getId(), '2026-10-02', 3_500));

        $this->assertThrows(PayrollRunNotApprovableException::class, fn () => $this->approve('owner', $runId));
        self::assertSame([], $this->events->events);

        ($this->regenerateHandler())(new RegeneratePayrollRunCommand($this->orgId(), $this->id('owner'), $runId));
        $this->approve('owner', $runId);
        self::assertSame(['employee' => 3_500], $this->totals($this->shown($runId, 'owner')));
    }

    public function test_it_refuses_to_approve_with_pending_change_requests_or_before_the_month_ends(): void
    {
        $this->work($this->employee, '2026-11-03', 3_600);
        $november = $this->generate('owner', '2026-11');
        $this->assertThrows(PayrollRunNotApprovableException::class, fn () => $this->approve('owner', $november));

        $this->work($this->employee, '2026-10-03', 3_600);
        $october = $this->generate('owner');
        $this->pending = 1;
        $this->assertThrows(PayrollRunNotApprovableException::class, fn () => $this->approve('owner', $october));
    }

    public function test_it_reopens_as_an_owner_and_publishes_the_event(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('owner');
        $reopen = fn (string $actor) => (new ReopenPayrollRunHandler($this->runs, $this->queryBus, $this->events))(new ReopenPayrollRunCommand($this->orgId(), $this->id($actor), $runId));

        $this->assertThrows(PayrollRunStatusException::class, fn () => $reopen('owner'));
        $this->approve('owner', $runId);
        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $reopen('manager'));
        $reopen('owner');

        self::assertSame('draft', $this->shown($runId, 'owner')->status);
        self::assertEquals(new PayrollRunReopened($this->orgId(), $runId, $this->id('owner')), $this->events->events[1]);
    }

    public function test_it_deletes_a_draft_only(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('owner');
        $delete = fn (string $actor) => (new DeletePayrollRunHandler($this->runs, $this->queryBus))(new DeletePayrollRunCommand($this->orgId(), $this->id($actor), $runId));
        $this->approve('owner', $runId);

        $this->assertThrows(PayrollRunStatusException::class, fn () => $delete('owner'));
        (new ReopenPayrollRunHandler($this->runs, $this->queryBus, $this->events))(new ReopenPayrollRunCommand($this->orgId(), $this->id('owner'), $runId));
        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $delete('employee'));
        $delete('manager');

        self::assertSame([], $this->runs->findAll());
    }

    public function test_it_adjusts_paychecks_of_members_active_or_deactivated(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('owner');
        $bonus = Uuid::v7()->toRfc4122();
        $add = fn (string $adjustmentId, User $user, int $amount) => (new AddPaycheckAdjustmentHandler($this->runs, $this->queryBus, $this->clock))(
            new AddPaycheckAdjustmentCommand($this->orgId(), $this->id('manager'), $runId, $adjustmentId, $user->getId()->toRfc4122(), 'Bonus', $amount),
        );

        $add($bonus, $this->employee, 1_000);
        $add(Uuid::v7()->toRfc4122(), $this->former, 2_000);
        $this->assertThrows(MemberNotFoundException::class, fn () => $add(Uuid::v7()->toRfc4122(), $this->outsider, 2_000));
        self::assertSame(['employee' => 4_000, 'former' => 2_000], $this->totals($this->shown($runId, 'owner')));

        (new ChangePaycheckAdjustmentHandler($this->runs, $this->queryBus, $this->clock))(new ChangePaycheckAdjustmentCommand($this->orgId(), $this->id('owner'), $runId, $bonus, 'Christmas bonus', 1_500));
        $adjustment = $this->shown($runId, 'owner')->paychecks[0]->adjustments[0];
        self::assertSame(['Christmas bonus', 1_500], [$adjustment->label, $adjustment->amount]);

        $remove = fn (string $adjustmentId, string $actor = 'owner') => (new RemovePaycheckAdjustmentHandler($this->runs, $this->queryBus))(new RemovePaycheckAdjustmentCommand($this->orgId(), $this->id($actor), $runId, $adjustmentId));
        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $remove($bonus, 'employee'));
        $this->assertThrows(PaycheckAdjustmentNotFoundException::class, fn () => $remove('nope'));
        $remove($bonus);
        self::assertSame(['employee' => 3_000, 'former' => 2_000], $this->totals($this->shown($runId, 'owner')));
    }

    public function test_it_shows_an_employee_only_their_own_paycheck_of_approved_runs(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $this->work($this->colleague, '2026-10-03', 7_200);
        $runId = $this->generate('owner');

        self::assertNull($this->view($runId, 'employee'), 'A draft is not shown to employees');
        self::assertSame([], $this->list('employee'));

        $this->approve('owner', $runId);

        $run = $this->view($runId, 'employee');
        self::assertNotNull($run);
        self::assertSame(['employee' => 3_000], $this->totals($run));
        self::assertSame(3_000, $run->total);
        self::assertNull($this->view($runId, 'outsider'));
        $summaries = $this->list('employee');
        self::assertCount(1, $summaries);
        self::assertSame([1, 3_600, 3_000], [$summaries[0]->paychecks, $summaries[0]->paidSeconds, $summaries[0]->total]);
        self::assertSame([2, 10_800, 9_000], [$this->list('manager')[0]->paychecks, $this->list('manager')[0]->paidSeconds, $this->list('manager')[0]->total]);
        self::assertNull((new ListPayrollRunsHandler($this->runs, $this->queryBus))(new ListPayrollRunsQuery($this->orgId(), $this->id('outsider'))));
        self::assertNull($this->view(Uuid::v7()->toRfc4122(), 'owner'));
        self::assertNull($this->view('not-a-uuid', 'owner'));
    }

    public function test_it_marks_paychecks_paid_as_an_owner_and_shows_the_run_paid_when_all_are(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $this->work($this->colleague, '2026-10-03', 7_200);
        $runId = $this->generate('owner');
        [$employees, $colleagues] = $this->paycheckIds($runId);

        $this->assertThrows(PayrollRunStatusException::class, fn () => $this->markPaid('owner', $runId, $employees));
        $this->approve('owner', $runId);
        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $this->markPaid('manager', $runId, $employees));
        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $this->markPaid('employee', $runId, $employees));
        $this->assertThrows(OrganizationNotFoundException::class, fn () => $this->markPaid('outsider', $runId, $employees));
        $this->assertThrows(PayrollRunNotFoundException::class, fn () => $this->markPaid('owner', Uuid::v7()->toRfc4122(), $employees));
        $this->assertThrows(PaycheckNotFoundException::class, fn () => $this->markPaid('owner', $runId, Uuid::v7()->toRfc4122()));
        $this->assertThrows(PaycheckNotFoundException::class, fn () => $this->markPaid('owner', $runId, 'not-a-uuid'));
        $this->assertThrows(PaymentDateInTheFutureException::class, fn () => $this->markPaid('owner', $runId, $employees, '2026-11-21'));

        $this->markPaid('owner', $runId, $employees, '2026-11-10', 'transfer');

        $run = $this->shown($runId, 'manager');
        self::assertSame([1, false], [$run->paidPaychecks, $run->paid]);
        $paycheck = array_column($run->paychecks, null, 'id')[$employees];
        self::assertSame(['2026-11-10', 'transfer', $this->id('owner')], [$paycheck->paidOn, $paycheck->paymentMethod, $paycheck->paidMarkedBy]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $paycheck->paidMarkedAt);
        self::assertSame([1, false], [$this->list('owner')[0]->paidPaychecks, $this->list('owner')[0]->paid]);
        // An employee sees only their own paycheck, so the run is paid for them once theirs is.
        $own = $this->shown($runId, 'employee');
        self::assertSame([1, true], [$own->paidPaychecks, $own->paid]);
        self::assertSame([1, true], [$this->list('employee')[0]->paidPaychecks, $this->list('employee')[0]->paid]);

        $this->assertThrows(PaycheckPaymentException::class, fn () => $this->markPaid('owner', $runId, $employees, '2026-11-12'));
        $this->markPaid('owner', $runId, $colleagues, '2026-11-20', 'cash');

        self::assertSame([2, true], [$this->shown($runId, 'owner')->paidPaychecks, $this->shown($runId, 'owner')->paid]);
        self::assertSame([2, true], [$this->list('manager')[0]->paidPaychecks, $this->list('manager')[0]->paid]);
    }

    public function test_it_takes_today_in_the_organizations_time_zone(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('owner');
        $this->approve('owner', $runId);
        [$paycheck] = $this->paycheckIds($runId);
        // 23:30 UTC on 20 November is 00:30 on 21 November in Warsaw.
        $this->clock = new MockClock('2026-11-20 23:30:00');

        $this->markPaid('owner', $runId, $paycheck, '2026-11-21');

        self::assertSame('2026-11-21', $this->shown($runId, 'owner')->paychecks[0]->paidOn);
    }

    public function test_it_undoes_a_paid_mark_as_an_owner_and_refuses_reopening_until_then(): void
    {
        $this->work($this->employee, '2026-10-03', 3_600);
        $runId = $this->generate('owner');
        $this->approve('owner', $runId);
        [$paycheck] = $this->paycheckIds($runId);
        $undo = fn (string $actor, string $id = '') => (new UndoPaycheckPaidHandler($this->runs, $this->queryBus))(new UndoPaycheckPaidCommand($this->orgId(), $this->id($actor), $runId, '' === $id ? $paycheck : $id));
        $reopen = fn () => (new ReopenPayrollRunHandler($this->runs, $this->queryBus, $this->events))(new ReopenPayrollRunCommand($this->orgId(), $this->id('owner'), $runId));

        $this->assertThrows(PaycheckPaymentException::class, fn () => $undo('owner'));
        $this->markPaid('owner', $runId, $paycheck);
        $this->assertThrows(PaycheckPaymentException::class, $reopen);
        self::assertCount(1, $this->events->events, 'No PayrollRunReopened when reopening is refused');
        $this->assertThrows(PayrollAccessForbiddenException::class, fn () => $undo('manager'));
        $this->assertThrows(PaycheckNotFoundException::class, fn () => $undo('owner', Uuid::v7()->toRfc4122()));

        $undo('owner');

        $run = $this->shown($runId, 'owner');
        self::assertSame([0, false, null, null], [$run->paidPaychecks, $run->paid, $run->paychecks[0]->paidOn, $run->paychecks[0]->paymentMethod]);
        $reopen();
        self::assertSame('draft', $this->shown($runId, 'owner')->status);
    }

    private function markPaid(string $actor, string $runId, string $paycheckId, string $paidOn = '2026-11-20', string $method = 'cash'): void
    {
        (new MarkPaycheckPaidHandler($this->runs, $this->queryBus, $this->clock))(
            new MarkPaycheckPaidCommand($this->orgId(), $this->id($actor), $runId, $paycheckId, $paidOn, $method),
        );
    }

    /** @return list<string> paycheck ids in the order employee, colleague, former (those present) */
    private function paycheckIds(string $runId): array
    {
        $byUser = [];
        foreach ($this->shown($runId, 'owner')->paychecks as $paycheck) {
            $byUser[$paycheck->userId] = $paycheck->id;
        }

        return array_values(array_filter(array_map(fn (string $name): ?string => $byUser[$this->id($name)] ?? null, ['employee', 'colleague', 'former'])));
    }

    private function generate(string $actor, string $month = '2026-10', string $unit = 'none', string $direction = 'nearest'): string
    {
        $runId = Uuid::v7()->toRfc4122();
        (new GeneratePayrollRunHandler($this->runs, new PayrollSourcesLoader($this->queryBus), $this->queryBus, $this->clock))(
            new GeneratePayrollRunCommand($this->orgId(), $this->id($actor), $runId, $month, $unit, $direction),
        );

        return $runId;
    }

    private function approve(string $actor, string $runId): void
    {
        (new ApprovePayrollRunHandler($this->runs, new PayrollSourcesLoader($this->queryBus), $this->queryBus, $this->events, $this->clock))(
            new ApprovePayrollRunCommand($this->orgId(), $this->id($actor), $runId),
        );
    }

    private function regenerateHandler(): RegeneratePayrollRunHandler
    {
        return new RegeneratePayrollRunHandler($this->runs, new PayrollSourcesLoader($this->queryBus), $this->queryBus, $this->clock);
    }

    private function view(string $runId, string $actor): ?PayrollRunView
    {
        return (new GetPayrollRunHandler($this->runs, $this->queryBus))(new GetPayrollRunQuery($this->orgId(), $this->id($actor), $runId));
    }

    private function shown(string $runId, string $actor): PayrollRunView
    {
        return $this->view($runId, $actor) ?? throw new \LogicException('Run expected.');
    }

    /** @return list<PayrollRunSummaryView> */
    private function list(string $actor): array
    {
        return (new ListPayrollRunsHandler($this->runs, $this->queryBus))(new ListPayrollRunsQuery($this->orgId(), $this->id($actor))) ?? [];
    }

    /** @return array<string, int> paycheck totals by actor name */
    private function totals(?PayrollRunView $run): array
    {
        self::assertNotNull($run);
        $names = [];
        foreach (['owner', 'manager', 'employee', 'colleague', 'former'] as $name) {
            $names[$this->id($name)] = $name;
        }
        $totals = [];
        foreach ($run->paychecks as $paycheck) {
            $totals[$names[$paycheck->userId]] = $paycheck->total;
        }

        return $totals;
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
            array_values(array_filter($this->shifts, static fn (WorkedShiftView $shift): bool => $shift->date >= $query->from && $shift->date <= $query->to)),
            $this->pending,
        );
    }

    private function id(string $actor): string
    {
        $user = match ($actor) {
            'owner' => $this->owner,
            'manager' => $this->manager,
            'employee' => $this->employee,
            'colleague' => $this->colleague,
            'former' => $this->former,
            default => $this->outsider,
        };

        return $user->getId()->toRfc4122();
    }

    private function orgId(): string
    {
        return $this->organization->getId()->toRfc4122();
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
