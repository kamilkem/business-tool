<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Application\IncomeMonthLock;
use App\Income\Application\Query\ListClosedRevenueMonths\ListClosedRevenueMonthsQuery;
use App\Income\Domain\Model\RevenueMonthLocks;
use App\CashFlow\Application\Command\CorrectDayClose\CorrectDayCloseCommand;
use App\CashFlow\Application\Command\CorrectDayClose\CorrectDayCloseHandler;
use App\CashFlow\Application\Command\DeleteCashMovement\DeleteCashMovementCommand;
use App\CashFlow\Application\Command\DeleteCashMovement\DeleteCashMovementHandler;
use App\CashFlow\Application\Command\DeleteDayClose\DeleteDayCloseCommand;
use App\CashFlow\Application\Command\DeleteDayClose\DeleteDayCloseHandler;
use App\CashFlow\Application\Command\RecalculateDayClose\RecalculateDayCloseCommand;
use App\CashFlow\Application\Command\RecalculateDayClose\RecalculateDayCloseHandler;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentCommand;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentHandler;
use App\CashFlow\Application\Command\RecordDayClose\RecordDayCloseCommand;
use App\CashFlow\Application\Command\RecordDayClose\RecordDayCloseHandler;
use App\CashFlow\Application\DayCloseBooking;
use App\CashFlow\Application\DayCloseOffers;
use App\CashFlow\Application\DayCloseRules;
use App\CashFlow\Application\EventHandler\RecalculateDayClosesOnCashLedgerChanged;
use App\CashFlow\Application\ExpectedOpenings;
use App\CashFlow\Domain\Event\CashLedgerChanged;
use App\CashFlow\Domain\Event\DayCloseChanged;
use App\CashFlow\Domain\Event\DayCloseDeleted;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DayCloseNotFoundException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\IncomeMonthClosedException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\Income\Application\Query\ListBookablePaymentMethods\ListBookablePaymentMethodsHandler;
use App\Income\Application\Query\ListBookablePaymentMethods\ListBookablePaymentMethodsQuery;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersHandler;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersQuery;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsHandler;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsQuery;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetMemberName\GetMemberNameHandler;
use App\Organization\Application\Query\GetMemberName\GetMemberNameQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Event\EventInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Income\Application\InMemoryPaymentMethodRepository;
use App\Tests\Unit\Income\Application\InMemoryRegisterRepository;
use App\Tests\Unit\Income\Application\InMemoryRevenueEntryRepository;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\DispatchingEventPublisher;
use App\Tests\Unit\Shared\Application\RecordingCommandBus;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Corrections, deletes and the recalculation of the next close (ADR 0033 §7), with Cash flow's
 * event handler and commands wired as the synchronous buses run them.
 *
 * A Polish organization (PLN, Europe/Warsaw) with a counted cash box, a deposit safe, three notes,
 * one register, a cash method and a card method. The employee closes three days in a row, at 00:30
 * in Warsaw each night (18, 19 and 20 November; the day ends at 05:00):
 *
 * - A: 300 zł in the box (cash income 300 zł from an empty box),
 * - B: 500 zł in the box (200 zł),
 * - C: 600 zł in the box (100 zł).
 */
#[CoversClass(CorrectDayCloseHandler::class)]
#[CoversClass(DeleteDayCloseHandler::class)]
#[CoversClass(RecalculateDayCloseHandler::class)]
#[CoversClass(RecalculateDayClosesOnCashLedgerChanged::class)]
#[CoversClass(ExpectedOpenings::class)]
#[CoversClass(DayCloseBooking::class)]
#[CoversClass(CashLedgerWriter::class)]
#[CoversClass(DayClose::class)]
final class DayCloseCorrectionHandlersTest extends TestCase
{
    private MockClock $clock;
    private InMemoryCashStorageRepository $storages;
    private InMemoryDenominationRepository $denominations;
    private InMemoryCashMovementRepository $movements;
    private InMemoryDayCloseRepository $closes;
    private InMemoryRegisterRepository $registers;
    private InMemoryPaymentMethodRepository $paymentMethods;
    private DispatchingEventPublisher $events;
    private RecordingCommandBus $commandBus;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $formerManager;
    private CashStorage $box;
    private CashStorage $safe;
    private Denomination $fifty;
    private Denomination $hundred;
    private Denomination $twoHundred;
    private Register $bar;
    private PaymentMethod $card;
    /** @var list<string> YYYY-MM: the Income months closed */
    private array $closedMonths = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-18 23:30:00');
        $organizations = new InMemoryOrganizationRepository();
        $this->storages = new InMemoryCashStorageRepository();
        $this->denominations = new InMemoryDenominationRepository();
        $this->movements = new InMemoryCashMovementRepository();
        $this->closes = new InMemoryDayCloseRepository();
        $this->registers = new InMemoryRegisterRepository();
        $this->paymentMethods = new InMemoryPaymentMethodRepository();
        $entries = new InMemoryRevenueEntryRepository();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = new User(Uuid::v7(), 'mark@example.com', new \DateTimeImmutable('2026-01-01'), 'Mark', 'Manager');
        $this->employee = UserMother::any('eve@example.com');
        $this->formerManager = UserMother::any('fred@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->formerManager, MembershipRole::Manager, $joined);
        OrganizationMother::deactivateMember($this->organization, $this->formerManager);
        $organizations->add($this->organization);

        $registers = $this->registers;
        $paymentMethods = $this->paymentMethods;
        $queryBus = null;
        $closedMonths = &$this->closedMonths;
        $queryBus = new StubQueryBus(static function (QueryInterface $query) use ($organizations, $registers, $paymentMethods, $entries, &$queryBus, &$closedMonths): mixed {
            \assert($queryBus instanceof StubQueryBus);

            return match (true) {
                $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
                $query instanceof GetMemberNameQuery => (new GetMemberNameHandler($organizations))($query),
                $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
                $query instanceof ListClosedRevenueMonthsQuery => new RevenueMonthLocks($closedMonths),
                $query instanceof ListBookableRegistersQuery => (new ListBookableRegistersHandler($registers, $entries))($query),
                $query instanceof ListBookablePaymentMethodsQuery => (new ListBookablePaymentMethodsHandler($paymentMethods, $entries))($query),
                $query instanceof ListPaymentMethodsQuery => (new ListPaymentMethodsHandler($paymentMethods, $entries, $queryBus))($query),
                default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
            };
        });
        $this->queryBus = $queryBus;

        // The synchronous buses: CashLedgerChanged → RecalculateDayCloseCommand → DayCloseChanged.
        $this->commandBus = new RecordingCommandBus(function (CommandInterface $command): void {
            if ($command instanceof RecalculateDayCloseCommand) {
                (new RecalculateDayCloseHandler($this->closes, $this->openings(), new DayCloseBooking($this->events)))($command);
            }
        });
        $this->events = new DispatchingEventPublisher(function (EventInterface $event): void {
            if ($event instanceof CashLedgerChanged) {
                (new RecalculateDayClosesOnCashLedgerChanged($this->closes, $this->commandBus))($event);
            }
        });

        $ownerId = $this->owner->getId();
        $this->box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $ownerId, MembershipRole::Owner, $joined);
        $this->safe = CashStorage::create(Uuid::v7(), 'Sejf', CashStorageMode::Deposit, null, $ownerId, MembershipRole::Owner, $joined);
        $this->storages->add($this->box);
        $this->storages->add($this->safe);
        foreach ([5_000, 10_000, 20_000] as $value) {
            $this->denominations->add(Denomination::create(Uuid::v7(), 'PLN', $value, DenominationKind::Note, null, $ownerId, MembershipRole::Owner, $joined));
        }
        [$this->fifty, $this->hundred, $this->twoHundred] = $this->denominations->findByCurrency('PLN');

        $this->bar = Register::create(Uuid::v7(), 'Bar', null, $ownerId, MembershipRole::Owner, $joined);
        $this->registers->add($this->bar);
        $cash = PaymentMethod::create(Uuid::v7(), 'Gotówka', null, 1, true, null, $ownerId, MembershipRole::Owner, $joined);
        $this->card = PaymentMethod::create(Uuid::v7(), 'Karta', null, 2, false, $cash, $ownerId, MembershipRole::Owner, $joined);
        $this->paymentMethods->add($cash);
        $this->paymentMethods->add($this->card);
    }

    public function test_it_recalculates_the_corrected_close_and_the_next_one_and_stops_at_its_count(): void
    {
        [$a, $b, $c] = $this->threeCloses();

        $this->correct($a, box: [[$this->hundred, 2]]);

        self::assertSame([20_000, 30_000, 10_000], $this->cashIncomes($a, $b, $c));
        self::assertSame(20_000, $this->closeOf($b)->getStorages()[0]->getExpectedOpening());
        self::assertSame([$a, $b], $this->changedCloses());
        $changedB = $this->events->eventsOf(DayCloseChanged::class)[1];
        self::assertSame([$this->organizationId(), '2026-11-19', 30_000, $this->manager->getId()->toRfc4122()], [$changedB->organizationId, $changedB->date, $changedB->cashIncome, $changedB->actorId]);
    }

    public function test_it_records_who_corrected_a_close_and_when_but_not_for_a_recalculation(): void
    {
        [$a, $b] = $this->threeCloses();

        $this->correct($a, box: [[$this->hundred, 2]]);

        self::assertTrue($this->manager->getId()->equals($this->closeOf($a)->getUpdatedBy() ?? Uuid::v7()));
        self::assertEquals(new \DateTimeImmutable('2026-11-21 23:30:00'), $this->closeOf($a)->getUpdatedAt());
        self::assertTrue($this->employee->getId()->equals($this->closeOf($a)->getClosedBy()));
        self::assertNull($this->closeOf($b)->getUpdatedBy());
    }

    public function test_it_recalculates_only_the_first_close_after_a_backdated_adjustment_and_again_when_it_is_deleted(): void
    {
        [$a, $b, $c] = $this->threeCloses();

        // A manager records late that they put 100 zł of change into the box on 19 November.
        $movement = $this->adjust($this->box, [[$this->hundred, 1]], occurredAt: '2026-11-19T12:00:00+00:00');

        self::assertSame([30_000, 10_000, 10_000], $this->cashIncomes($a, $b, $c));
        self::assertSame(40_000, $this->closeOf($b)->getStorages()[0]->getExpectedOpening());
        self::assertSame([$b], $this->changedCloses());

        $this->events->events = [];
        $this->deleteMovement($movement);

        self::assertSame([30_000, 20_000, 10_000], $this->cashIncomes($a, $b, $c));
        self::assertSame([$b], $this->changedCloses());
    }

    public function test_it_recalculates_no_close_for_a_movement_after_the_last_one(): void
    {
        $this->threeCloses();

        $this->adjust($this->box, [[$this->hundred, -1]]);

        self::assertSame([], $this->changedCloses());
        self::assertSame([], $this->commandBus->commandsOf(RecalculateDayCloseCommand::class));
    }

    public function test_it_moves_a_deleted_closes_cash_into_the_next_close(): void
    {
        [$a, $b, $c] = $this->threeCloses();

        $this->delete($a);

        self::assertNull($this->closes->findById(Uuid::fromString($a)));
        // B now counts 500 zł from an empty box.
        self::assertSame([50_000, 10_000], $this->cashIncomes($b, $c));
        $deleted = $this->events->eventsOf(DayCloseDeleted::class);
        self::assertEquals([new DayCloseDeleted($this->organizationId(), $a, $this->bar->getId()->toRfc4122(), '2026-11-18', $this->manager->getId()->toRfc4122())], $deleted);
        self::assertSame([$b], $this->changedCloses());
    }

    public function test_it_skips_a_close_that_did_not_count_the_storage(): void
    {
        $a = $this->close(box: [[$this->hundred, 3]]);
        // For one night the box takes deposits, then it is counted again.
        $this->box->changeMode(CashStorageMode::Deposit, $this->owner->getId(), MembershipRole::Owner);
        $b = $this->close(box: [[$this->hundred, 1]]);
        $this->box->changeMode(CashStorageMode::Counted, $this->owner->getId(), MembershipRole::Owner);
        $c = $this->close(box: [[$this->hundred, 6]]);
        self::assertSame([30_000, 10_000, 20_000], $this->cashIncomes($a, $b, $c));
        $this->events->events = [];

        $this->adjust($this->box, [[$this->hundred, 1]], occurredAt: '2026-11-19T12:00:00+00:00');

        // B's deposit is its cash income whatever the box held; C's expected opening is 500 zł now.
        self::assertSame([30_000, 10_000, 10_000], $this->cashIncomes($a, $b, $c));
        self::assertSame([$c], $this->changedCloses());
    }

    public function test_it_recalculates_the_next_close_of_each_storage_once(): void
    {
        $drawer = CashStorage::create(Uuid::v7(), 'Szuflada', CashStorageMode::Counted, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-01-05'));
        $this->storages->add($drawer);
        $a = $this->close(box: [[$this->hundred, 3]], drawer: [$drawer, [[$this->fifty, 2]]]);
        $b = $this->close(box: [[$this->hundred, 3]], drawer: [$drawer, [[$this->fifty, 2]]]);
        $this->events->events = [];
        $this->commandBus->commands = [];

        $this->correct($a, box: [[$this->hundred, 2]], drawer: [$drawer, [[$this->fifty, 1]]]);

        self::assertCount(1, $this->commandBus->commandsOf(RecalculateDayCloseCommand::class));
        // B: 300 zł − 200 zł in the box, 100 zł − 50 zł in the drawer.
        self::assertSame([25_000, 15_000], $this->cashIncomes($a, $b));
    }

    public function test_it_flags_a_shortage_when_a_backdated_adjustment_raises_the_expected_opening(): void
    {
        [$a, $b] = $this->threeCloses();

        $this->adjust($this->box, [[$this->twoHundred, 2]], occurredAt: '2026-11-19T12:00:00+00:00');

        // B counted 500 zł, but 700 zł were expected.
        $close = $this->closeOf($b);
        self::assertSame([-20_000, true], [$close->getCashIncome(), $close->isShortage()]);
        self::assertSame(-20_000, $this->events->eventsOf(DayCloseChanged::class)[0]->cashIncome);
        self::assertSame(30_000, $this->closeOf($a)->getCashIncome());
    }

    public function test_it_saves_a_correction_that_makes_a_shortage_and_the_next_close_makes_up_for_it(): void
    {
        [, $b, $c] = $this->threeCloses();

        $this->correct($b, box: [[$this->hundred, 2]]);

        self::assertSame([-10_000, 40_000], $this->cashIncomes($b, $c));
        self::assertTrue($this->closeOf($b)->isShortage());
    }

    public function test_it_corrects_deposits_and_non_cash_amounts_without_changing_the_next_close(): void
    {
        [$a, $b] = $this->threeCloses();

        $this->correct($a, box: [[$this->hundred, 3]], safe: [[$this->twoHundred, 1]], card: 12_000);

        self::assertSame([50_000, 20_000], $this->cashIncomes($a, $b));
        $changed = $this->events->eventsOf(DayCloseChanged::class);
        self::assertCount(1, $changed);
        self::assertSame([50_000, [$this->card->getId()->toRfc4122() => 12_000]], [$changed[0]->cashIncome, $changed[0]->nonCashAmounts]);
        self::assertSame([[$this->twoHundred->getId()->toRfc4122(), 1]], $this->linesOf($a, $this->safe));
    }

    public function test_it_publishes_no_change_when_what_income_books_stays_the_same(): void
    {
        [$a] = $this->threeCloses();

        // The same 300 zł, counted as other notes.
        $this->correct($a, box: [[$this->fifty, 2], [$this->twoHundred, 1]]);

        self::assertSame([], $this->changedCloses());
        self::assertSame([[$this->fifty->getId()->toRfc4122(), 2], [$this->hundred->getId()->toRfc4122(), 0], [$this->twoHundred->getId()->toRfc4122(), 1]], $this->linesOf($a, $this->box));
        self::assertCount(1, $this->events->eventsOf(CashLedgerChanged::class));
    }

    public function test_it_moves_a_close_to_another_active_register_and_keeps_its_own_once_deactivated(): void
    {
        [$a] = $this->threeCloses();
        $kitchen = Register::create(Uuid::v7(), 'Kuchnia', null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-01-05'));
        $this->registers->add($kitchen);

        $this->correct($a, box: [[$this->hundred, 3]], register: $kitchen->getId()->toRfc4122());
        self::assertSame($kitchen->getId()->toRfc4122(), $this->events->eventsOf(DayCloseChanged::class)[0]->registerId);

        $kitchen->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());
        $this->correct($a, box: [[$this->hundred, 4]], register: $kitchen->getId()->toRfc4122());
        self::assertTrue($kitchen->getId()->equals($this->closeOf($a)->getRegisterId()));

        $this->bar->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());
        $this->expectCode('cash_flow.invalid_day_close.register_not_offered', fn () => $this->correct($a, register: $this->bar->getId()->toRfc4122()));
    }

    public function test_it_refuses_a_correction_that_does_not_cover_what_the_close_covers(): void
    {
        [$a] = $this->threeCloses();
        $added = CashStorage::create(Uuid::v7(), 'Nowa', CashStorageMode::Counted, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-20'));
        $this->storages->add($added);

        $this->expectCode('cash_flow.invalid_day_close.storage_not_in_close', fn () => $this->correct($a, drawer: [$added, []]));
        $this->expectCode('cash_flow.invalid_day_close.missing_storage', fn () => $this->handleCorrection($this->correction($a, [$this->storageLines($this->box, [])])));
        $this->expectCode('cash_flow.invalid_day_close.payment_method_not_in_close', fn () => $this->handleCorrection($this->correction($a, payments: [['paymentMethodId' => Uuid::v7()->toRfc4122(), 'amount' => 0]])));
        $this->expectCode('cash_flow.invalid_day_close.missing_payment_method', fn () => $this->handleCorrection($this->correction($a, payments: [])));
        $this->expectCode('cash_flow.invalid_day_close.pieces', fn () => $this->correct($a, box: [[$this->hundred, 5]], safe: [[$this->hundred, -1]]));

        // A refused correction changes nothing.
        self::assertSame(30_000, $this->closeOf($a)->getCashIncome());
        self::assertSame([[$this->fifty->getId()->toRfc4122(), 0], [$this->hundred->getId()->toRfc4122(), 3], [$this->twoHundred->getId()->toRfc4122(), 0]], $this->linesOf($a, $this->box));
        self::assertNull($this->closeOf($a)->getUpdatedBy());
    }

    public function test_it_lets_a_correction_count_a_denomination_deactivated_since_the_close(): void
    {
        [$a] = $this->threeCloses();
        $this->fifty->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());

        $this->correct($a, box: [[$this->hundred, 3], [$this->fifty, 1]]);

        self::assertSame(35_000, $this->closeOf($a)->getCashIncome());
    }

    public function test_it_refuses_a_correction_or_delete_that_takes_a_deposit_storage_below_zero(): void
    {
        $a = $this->close(box: [[$this->hundred, 3]], safe: [[$this->twoHundred, 2]]);
        // The manager takes the 400 zł from the safe to the bank.
        $this->adjust($this->safe, [[$this->twoHundred, -2]]);

        $this->expectExceptionThrown(StorageBalanceNegativeException::class, fn () => $this->correct($a, box: [[$this->hundred, 3]], safe: [[$this->twoHundred, 1]]));
        $this->expectExceptionThrown(StorageBalanceNegativeException::class, fn () => $this->delete($a));
        self::assertNotNull($this->closes->findById(Uuid::fromString($a)));
    }

    public function test_it_refuses_to_record_correct_or_delete_a_close_in_a_closed_income_month(): void
    {
        [$a] = $this->threeCloses();
        $this->closedMonths = ['2026-11'];

        $this->expectIncomeMonthClosed('cash_flow.income_month_closed.for_date', fn () => $this->correct($a, box: [[$this->hundred, 2]]));
        $this->expectIncomeMonthClosed('cash_flow.income_month_closed.for_date', fn () => $this->delete($a));
        $this->expectIncomeMonthClosed('cash_flow.income_month_closed.for_date', fn () => $this->close(box: [[$this->hundred, 7]]));
        self::assertSame(30_000, $this->closeOf($a)->getCashIncome());
        self::assertSame([], $this->changedCloses());
    }

    public function test_it_refuses_a_backdated_movement_or_its_delete_that_changes_a_closed_months_close(): void
    {
        [, $b] = $this->threeCloses();
        $movement = $this->adjust($this->box, [[$this->hundred, 1]], occurredAt: '2026-11-19T12:00:00+00:00');
        $this->events->events = [];
        $this->closedMonths = ['2026-11'];

        try {
            $this->adjust($this->box, [[$this->hundred, 1]], occurredAt: '2026-11-19T13:00:00+00:00');
            self::fail('IncomeMonthClosedException expected.');
        } catch (IncomeMonthClosedException $e) {
            self::assertSame('cash_flow.income_month_closed.recalculated_close', $e->errorCode());
            self::assertSame(['dayCloseId' => $b, 'businessDate' => '2026-11-19', 'month' => '2026-11'], $e->errorParameters());
        }
        $this->expectIncomeMonthClosed('cash_flow.income_month_closed.recalculated_close', fn () => $this->deleteMovement($movement));
        self::assertNotNull($this->movements->findById(Uuid::fromString($movement)));
        self::assertCount(1, $this->movements->inLedgerOrder());
        self::assertSame([], $this->changedCloses());
    }

    public function test_it_lets_through_cash_writes_that_change_no_closed_months_close(): void
    {
        [, $b] = $this->threeCloses();
        $this->closedMonths = ['2026-10'];

        // Another month is closed: the close of 19 November is recalculated as before.
        $this->adjust($this->box, [[$this->hundred, 1]], occurredAt: '2026-11-19T12:00:00+00:00');
        self::assertSame(10_000, $this->closeOf($b)->getCashIncome());

        $this->closedMonths = ['2026-10', '2026-11'];
        // The safe is no close's counted storage, and nothing is closed after now.
        $this->adjust($this->safe, [[$this->twoHundred, 1]], occurredAt: '2026-11-19T12:00:00+00:00');
        $this->adjust($this->box, [[$this->hundred, -1]]);
        self::assertSame(10_000, $this->closeOf($b)->getCashIncome());
    }

    public function test_it_lets_only_owners_and_managers_correct_or_delete_a_close(): void
    {
        [$a, $b] = $this->threeCloses();

        $this->expectExceptionThrown(CashFlowAccessForbiddenException::class, fn () => $this->correct($a, actor: $this->employee));
        $this->expectExceptionThrown(CashFlowAccessForbiddenException::class, fn () => $this->delete($a, $this->employee));
        $this->expectExceptionThrown(OrganizationNotFoundException::class, fn () => $this->correct($a, actor: $this->formerManager));
        $this->expectExceptionThrown(OrganizationNotFoundException::class, fn () => $this->delete($a, $this->formerManager));
        $this->expectExceptionThrown(DayCloseNotFoundException::class, fn () => $this->delete(Uuid::v7()->toRfc4122()));

        $this->correct($b, box: [[$this->hundred, 6]], actor: $this->owner);
        $this->delete($a, $this->owner);
        self::assertSame(60_000, $this->closeOf($b)->getCashIncome());
    }

    /** @return array{string, string, string} */
    private function threeCloses(): array
    {
        $closes = [
            $this->close(box: [[$this->hundred, 3]]),
            $this->close(box: [[$this->hundred, 5]]),
            $this->close(box: [[$this->hundred, 6]]),
        ];
        self::assertSame([30_000, 20_000, 10_000], $this->cashIncomes(...$closes));
        $this->events->events = [];
        $this->commandBus->commands = [];

        return $closes;
    }

    /**
     * The employee closes the proposed date, and the clock moves on a day.
     *
     * @param list<array{Denomination, int}>                    $box
     * @param list<array{Denomination, int}>                    $safe
     * @param array{CashStorage, list<array{Denomination, int}>}|null $drawer
     */
    private function close(array $box = [], array $safe = [], ?array $drawer = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        $storages = [$this->storageLines($this->box, $box), $this->storageLines($this->safe, $safe)];
        if (null !== $drawer) {
            $storages[] = $this->storageLines(...$drawer);
        }

        (new RecordDayCloseHandler(
            $this->closes,
            $this->openings(),
            $this->writer(),
            new DayCloseOffers($this->storages, $this->denominations, $this->queryBus),
            new DayCloseRules(new InMemoryDayCloseSettingsRepository()),
            $this->incomeMonths(),
            $this->events,
            $this->queryBus,
            $this->clock,
        ))(new RecordDayCloseCommand(
            $this->organizationId(),
            $this->employee->getId()->toRfc4122(),
            $id,
            $this->bar->getId()->toRfc4122(),
            $storages,
            [['paymentMethodId' => $this->card->getId()->toRfc4122(), 'amount' => 10_000]],
        ));
        $this->clock->modify('+1 day');

        return $id;
    }

    /**
     * @param list<array{Denomination, int}>                    $box
     * @param list<array{Denomination, int}>                    $safe
     * @param array{CashStorage, list<array{Denomination, int}>}|null $drawer
     */
    private function correct(string $id, array $box = [], array $safe = [], int $card = 10_000, ?string $register = null, ?User $actor = null, ?array $drawer = null): void
    {
        $storages = [$this->storageLines($this->box, $box), $this->storageLines($this->safe, $safe)];
        if (null !== $drawer) {
            $storages[] = $this->storageLines(...$drawer);
        }

        $this->handleCorrection($this->correction($id, $storages, [['paymentMethodId' => $this->card->getId()->toRfc4122(), 'amount' => $card]], $register, $actor));
    }

    /**
     * @param list<array{storageId: string, lines: list<array{denominationId: string, pieces: int}>}>|null $storages
     * @param list<array{paymentMethodId: string, amount: int}>|null                                       $payments
     */
    private function correction(string $id, ?array $storages = null, ?array $payments = null, ?string $register = null, ?User $actor = null): CorrectDayCloseCommand
    {
        return new CorrectDayCloseCommand(
            $this->organizationId(),
            ($actor ?? $this->manager)->getId()->toRfc4122(),
            $id,
            $register ?? $this->bar->getId()->toRfc4122(),
            $storages ?? [$this->storageLines($this->box, []), $this->storageLines($this->safe, [])],
            $payments ?? [['paymentMethodId' => $this->card->getId()->toRfc4122(), 'amount' => 10_000]],
        );
    }

    private function handleCorrection(CorrectDayCloseCommand $command): void
    {
        (new CorrectDayCloseHandler(
            $this->closes,
            $this->denominations,
            $this->openings(),
            $this->writer(),
            $this->incomeMonths(),
            new DayCloseBooking($this->events),
            $this->queryBus,
            $this->clock,
        ))($command);
    }

    private function delete(string $id, ?User $actor = null): void
    {
        (new DeleteDayCloseHandler($this->closes, $this->writer(), $this->incomeMonths(), $this->events, $this->queryBus))(
            new DeleteDayCloseCommand($this->organizationId(), ($actor ?? $this->manager)->getId()->toRfc4122(), $id),
        );
    }

    /** @param list<array{Denomination, int}> $lines */
    private function adjust(CashStorage $storage, array $lines, ?string $occurredAt = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new RecordCashAdjustmentHandler($this->writer(), $this->queryBus, $this->clock))(new RecordCashAdjustmentCommand(
            $this->organizationId(),
            $this->manager->getId()->toRfc4122(),
            $id,
            $storage->getId()->toRfc4122(),
            self::lines($lines),
            null,
            $occurredAt,
        ));

        return $id;
    }

    private function deleteMovement(string $id): void
    {
        (new DeleteCashMovementHandler($this->movements, $this->writer(), $this->queryBus))(
            new DeleteCashMovementCommand($this->organizationId(), $this->manager->getId()->toRfc4122(), $id),
        );
    }

    /** @return list<string> the closes DayCloseChanged was published for, in order */
    private function changedCloses(): array
    {
        return array_map(static fn (DayCloseChanged $event): string => $event->dayCloseId, $this->events->eventsOf(DayCloseChanged::class));
    }

    /** @return list<int> */
    private function cashIncomes(string ...$ids): array
    {
        return array_map(fn (string $id): int => $this->closeOf($id)->getCashIncome(), array_values($ids));
    }

    private function closeOf(string $id): DayClose
    {
        return $this->closes->findById(Uuid::fromString($id)) ?? self::fail('Close expected.');
    }

    /** @return list<array{string, int}> denomination id and pieces, by value */
    private function linesOf(string $id, CashStorage $storage): array
    {
        $lines = [];
        foreach ($this->closeOf($id)->getLines() as $line) {
            if ($line->getStorage() === $storage) {
                $lines[$line->getDenomination()->getValue()] = [$line->getDenomination()->getId()->toRfc4122(), $line->getPieces()];
            }
        }
        ksort($lines);

        return array_values($lines);
    }

    /**
     * @param list<array{Denomination, int}> $lines
     *
     * @return array{storageId: string, lines: list<array{denominationId: string, pieces: int}>}
     */
    private function storageLines(CashStorage $storage, array $lines): array
    {
        return ['storageId' => $storage->getId()->toRfc4122(), 'lines' => self::lines($lines)];
    }

    /**
     * @param list<array{Denomination, int}> $lines
     *
     * @return list<array{denominationId: string, pieces: int}>
     */
    private static function lines(array $lines): array
    {
        return array_map(static fn (array $line): array => ['denominationId' => $line[0]->getId()->toRfc4122(), 'pieces' => $line[1]], $lines);
    }

    private function openings(): ExpectedOpenings
    {
        return new ExpectedOpenings(new InMemoryCashLedger($this->movements, $this->closes), $this->denominations);
    }

    private function writer(): CashLedgerWriter
    {
        return new CashLedgerWriter($this->movements, new InMemoryCashLedger($this->movements, $this->closes), $this->storages, $this->denominations, $this->events, $this->incomeMonths());
    }

    private function incomeMonths(): IncomeMonthLock
    {
        return new IncomeMonthLock($this->queryBus, $this->closes, new InMemoryCashLedger($this->movements, $this->closes), $this->openings());
    }

    private function organizationId(): string
    {
        return $this->organization->getId()->toRfc4122();
    }

    /** @param \Closure(): mixed $call */
    private function expectIncomeMonthClosed(string $code, \Closure $call): void
    {
        try {
            $call();
            self::fail(\sprintf('%s expected.', $code));
        } catch (IncomeMonthClosedException $e) {
            self::assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    /** @param \Closure(): mixed $call */
    private function expectCode(string $code, \Closure $call): void
    {
        try {
            $call();
            self::fail(\sprintf('%s expected.', $code));
        } catch (InvalidDayCloseException|DenominationNotOfferedException $e) {
            self::assertSame($code, $e->errorCode(), $e->getMessage());
        }
    }

    /**
     * @param class-string<\Throwable> $exception
     * @param \Closure(): mixed        $call
     */
    private function expectExceptionThrown(string $exception, \Closure $call): void
    {
        try {
            $call();
            self::fail(\sprintf('%s expected.', $exception));
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e, $e->getMessage());
        }
    }
}
