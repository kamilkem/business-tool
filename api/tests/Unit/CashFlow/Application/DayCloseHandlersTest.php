<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentCommand;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentHandler;
use App\CashFlow\Application\Command\RecordDayClose\RecordDayCloseCommand;
use App\CashFlow\Application\Command\RecordDayClose\RecordDayCloseHandler;
use App\CashFlow\Application\DayCloseOffers;
use App\CashFlow\Application\DayCloseRules;
use App\CashFlow\Application\DayCloseViews;
use App\CashFlow\Application\ExpectedOpenings;
use App\CashFlow\Application\Query\GetDayClose\DayCloseView;
use App\CashFlow\Application\Query\GetDayClose\GetDayCloseHandler;
use App\CashFlow\Application\Query\GetDayClose\GetDayCloseQuery;
use App\CashFlow\Application\Query\GetDayCloseForm\DayCloseFormView;
use App\CashFlow\Application\Query\GetDayCloseForm\GetDayCloseFormHandler;
use App\CashFlow\Application\Query\GetDayCloseForm\GetDayCloseFormQuery;
use App\CashFlow\Application\Query\GetDayCloseReceipt\GetDayCloseReceiptHandler;
use App\CashFlow\Application\Query\GetDayCloseReceipt\GetDayCloseReceiptQuery;
use App\CashFlow\Application\Query\ListDayCloses\ListDayClosesHandler;
use App\CashFlow\Application\Query\ListDayCloses\ListDayClosesQuery;
use App\CashFlow\Domain\Event\DayCloseRecorded;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Exception\DayClosePeriodTooLongException;
use App\CashFlow\Domain\Exception\InvalidDayCloseDateException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayCloseDateMode;
use App\CashFlow\Domain\Model\DayCloseRule;
use App\CashFlow\Domain\Model\DayCloseSettings;
use App\CashFlow\Domain\Model\DayEndTime;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\Income\Application\Query\ListBookablePaymentMethods\ListBookablePaymentMethodsHandler;
use App\Income\Application\Query\ListBookablePaymentMethods\ListBookablePaymentMethodsQuery;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersHandler;
use App\Income\Application\Query\ListBookableRegisters\ListBookableRegistersQuery;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsHandler;
use App\Income\Application\Query\ListPaymentMethods\ListPaymentMethodsQuery;
use App\Income\Application\Query\ListRegisters\ListRegistersHandler;
use App\Income\Application\Query\ListRegisters\ListRegistersQuery;
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
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Income\Application\InMemoryPaymentMethodRepository;
use App\Tests\Unit\Income\Application\InMemoryRegisterRepository;
use App\Tests\Unit\Income\Application\InMemoryRevenueEntryRepository;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * A Polish organization (PLN, Europe/Warsaw) with a counted cash box, a deposit safe, three notes,
 * one register, a cash method and a card method, and an owner, a manager, an employee and a former
 * employee. It is 01:30 in Warsaw on 22 November, so with the default settings (day ends at 05:00)
 * a close now is for 21 November.
 */
#[CoversClass(RecordDayCloseHandler::class)]
#[CoversClass(DayCloseOffers::class)]
#[CoversClass(DayCloseViews::class)]
#[CoversClass(GetDayCloseHandler::class)]
#[CoversClass(GetDayCloseReceiptHandler::class)]
#[CoversClass(GetDayCloseFormHandler::class)]
#[CoversClass(ListDayClosesHandler::class)]
#[CoversClass(ListBookableRegistersHandler::class)]
#[CoversClass(ListBookablePaymentMethodsHandler::class)]
#[CoversClass(GetMemberNameHandler::class)]
final class DayCloseHandlersTest extends TestCase
{
    private const string NOW = '2026-11-22 00:30:00';

    private MockClock $clock;
    private InMemoryCashStorageRepository $storages;
    private InMemoryDenominationRepository $denominations;
    private InMemoryCashMovementRepository $movements;
    private InMemoryDayCloseRepository $closes;
    private InMemoryDayCloseSettingsRepository $settings;
    private InMemoryRegisterRepository $registers;
    private InMemoryPaymentMethodRepository $paymentMethods;
    private RecordingEventPublisher $events;
    private StubQueryBus $queryBus;
    private Organization $organization;
    private User $owner;
    private User $manager;
    private User $employee;
    private User $formerEmployee;
    private CashStorage $box;
    private CashStorage $safe;
    private Denomination $fifty;
    private Denomination $hundred;
    private Denomination $twoHundred;
    private Register $bar;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->storages = new InMemoryCashStorageRepository();
        $this->denominations = new InMemoryDenominationRepository();
        $this->movements = new InMemoryCashMovementRepository();
        $this->closes = new InMemoryDayCloseRepository();
        $this->settings = new InMemoryDayCloseSettingsRepository();
        $this->registers = new InMemoryRegisterRepository();
        $this->paymentMethods = new InMemoryPaymentMethodRepository();
        $entries = new InMemoryRevenueEntryRepository();
        $this->events = new RecordingEventPublisher();
        $this->owner = UserMother::any('olivia@example.com');
        $this->manager = new User(Uuid::v7(), 'mark@example.com', new \DateTimeImmutable('2026-01-01'), 'Mark', 'Manager');
        $this->employee = new User(Uuid::v7(), 'eve@example.com', new \DateTimeImmutable('2026-01-01'), 'Eve', 'Employee');
        $this->formerEmployee = UserMother::any('fred@example.com');
        $joined = new \DateTimeImmutable('2026-01-05 09:00:00');
        $this->organization = OrganizationMother::ownedBy($this->owner, createdAt: $joined);
        OrganizationMother::addMember($this->organization, $this->manager, MembershipRole::Manager, $joined);
        OrganizationMother::addMember($this->organization, $this->employee, MembershipRole::Employee, $joined);
        OrganizationMother::addMember($this->organization, $this->formerEmployee, MembershipRole::Employee, $joined);
        OrganizationMother::deactivateMember($this->organization, $this->formerEmployee);
        $organizations->add($this->organization);

        $registers = $this->registers;
        $paymentMethods = $this->paymentMethods;
        $bus = null;
        $bus = new StubQueryBus(static function (QueryInterface $query) use ($organizations, $registers, $paymentMethods, $entries, &$bus): mixed {
            \assert($bus instanceof StubQueryBus);

            return match (true) {
                $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
                $query instanceof GetMemberNameQuery => (new GetMemberNameHandler($organizations))($query),
                $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
                $query instanceof ListBookableRegistersQuery => (new ListBookableRegistersHandler($registers, $entries))($query),
                $query instanceof ListBookablePaymentMethodsQuery => (new ListBookablePaymentMethodsHandler($paymentMethods, $entries))($query),
                $query instanceof ListRegistersQuery => (new ListRegistersHandler($registers, $entries, $bus))($query),
                $query instanceof ListPaymentMethodsQuery => (new ListPaymentMethodsHandler($paymentMethods, $entries, $bus))($query),
                default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
            };
        });
        $this->queryBus = $bus;

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

    public function test_it_lets_an_employee_close_the_date_of_the_day_end_time_and_publishes_what_income_books(): void
    {
        $id = $this->close($this->employee, box: [[$this->hundred, 3]], safe: [[$this->twoHundred, 1]], card: 45_000);

        $receipt = $this->receipt($this->employee, $id);
        self::assertSame(['2026-11-21', 'Bar', 'PLN', 50_000, false], [$receipt->date, $receipt->registerName, $receipt->currency, $receipt->cashIncome, $receipt->shortage]);
        self::assertSame([['Kasetka', 'counted', 30_000, null], ['Sejf', 'deposit', 20_000, null]], self::storages($receipt));
        self::assertSame([[$this->card->getId()->toRfc4122(), 'Karta', 45_000]], array_map(static fn ($p): array => [$p->paymentMethodId, $p->name, $p->amount], $receipt->payments));

        self::assertEquals([new DayCloseRecorded(
            $this->organization->getId()->toRfc4122(),
            $id,
            $this->bar->getId()->toRfc4122(),
            '2026-11-21',
            'PLN',
            50_000,
            [$this->card->getId()->toRfc4122() => 45_000],
            $this->employee->getId()->toRfc4122(),
        )], $this->events->events);
    }

    public function test_it_takes_the_expected_opening_from_the_ledger_with_a_managers_adjustment_before_the_close(): void
    {
        $this->clock->modify('-1 day');
        $first = $this->close($this->employee, box: [[$this->hundred, 2]]);
        $this->clock->modify('+12 hours');
        // A manager takes 100 zł out of the box for change in the afternoon.
        $this->adjust($this->box, [[$this->hundred, -1]]);
        $this->clock->modify('+12 hours');

        $second = $this->close($this->employee, box: [[$this->hundred, 4], [$this->fifty, 1]]);

        self::assertSame(20_000, $this->details($first)->cashIncome);
        // 45 000 counted − (20 000 left yesterday − 10 000 taken out).
        $details = $this->details($second);
        self::assertSame(35_000, $details->cashIncome);
        self::assertSame([['Kasetka', 'counted', 45_000, 10_000], ['Sejf', 'deposit', 0, null]], self::storages($details));
    }

    public function test_it_includes_a_backdated_adjustment_recorded_before_the_close(): void
    {
        $this->adjust($this->box, [[$this->hundred, 5]], occurredAt: '2026-11-21T08:00:00+01:00');

        $id = $this->close($this->employee, box: [[$this->hundred, 5], [$this->twoHundred, 1]]);

        self::assertSame(20_000, $this->details($id)->cashIncome);
        self::assertSame(50_000, $this->details($id)->storages[0]->expectedOpening);
    }

    public function test_it_saves_and_flags_a_shortage(): void
    {
        $this->adjust($this->box, [[$this->hundred, 5]], occurredAt: '2026-11-21T08:00:00+01:00');

        $id = $this->close($this->employee, box: [[$this->hundred, 3]]);

        $details = $this->details($id);
        self::assertSame([-20_000, true], [$details->cashIncome, $details->shortage]);
        self::assertSame(-20_000, $this->receipt($this->employee, $id)->cashIncome);
    }

    public function test_it_lets_a_manager_close_a_past_date_but_not_a_future_one(): void
    {
        $id = $this->close($this->manager, date: '2026-11-15');

        self::assertSame('2026-11-15', $this->details($id)->date);
        $this->expectExceptionThrown(InvalidDayCloseDateException::class, fn () => $this->close($this->manager, date: '2026-11-23'));
    }

    public function test_it_lets_an_employee_pick_today_or_yesterday_only_in_employee_picks_mode(): void
    {
        $this->expectExceptionThrown(InvalidDayCloseDateException::class, fn () => $this->close($this->employee, date: '2026-11-22'));

        $this->settings->add(DayCloseSettings::create(Uuid::v7(), new DayCloseRule(DayCloseDateMode::EmployeePicks, DayEndTime::default()), $this->owner->getId(), MembershipRole::Owner, $this->clock->now()));
        $this->expectExceptionThrown(InvalidDayCloseDateException::class, fn () => $this->close($this->employee, date: '2026-11-20'));

        self::assertSame('2026-11-22', $this->details($this->close($this->employee, date: '2026-11-22'))->date);
    }

    public function test_it_refuses_a_second_close_of_a_date_and_says_who_closed_it(): void
    {
        $this->close($this->employee);

        try {
            $this->close($this->manager);
            self::fail('DayAlreadyClosedException expected.');
        } catch (DayAlreadyClosedException $e) {
            self::assertSame(['2026-11-21', 'Eve', 'Employee'], [$e->errorParameters()['date'], $e->errorParameters()['closedByFirstName'], $e->errorParameters()['closedByLastName']]);
        }
    }

    public function test_it_refuses_an_unknown_storage_an_inactive_register_and_a_former_member(): void
    {
        $this->expectExceptionThrown(StorageNotFoundException::class, fn () => $this->handle($this->command($this->employee, storages: [['storageId' => Uuid::v7()->toRfc4122(), 'lines' => []]])));

        $this->bar->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());
        $this->expectExceptionThrown(InvalidDayCloseException::class, fn () => $this->close($this->employee));
        $this->expectExceptionThrown(OrganizationNotFoundException::class, fn () => $this->close($this->formerEmployee));
        self::assertSame([], $this->events->events);
    }

    public function test_it_shows_the_close_form_to_any_member_without_balances(): void
    {
        $form = $this->form($this->employee);

        self::assertSame(['PLN', true, '2026-11-21', '2026-11-22', 'day_end_time', '05:00'], [$form->currency, $form->canClose, $form->proposedDate, $form->today, $form->mode, $form->dayEndsAt]);
        self::assertSame([['Kasetka', 'counted'], ['Sejf', 'deposit']], array_map(static fn ($s): array => [$s->name, $s->kind], $form->storages));
        self::assertSame([5_000, 10_000, 20_000], array_map(static fn ($d): ?int => $d->value, $form->denominations));
        self::assertSame([['Bar', $this->bar->getId()->toRfc4122()]], array_map(static fn ($r): array => [$r->name, $r->id], $form->registers));
        self::assertSame($this->bar->getId()->toRfc4122(), $form->preselectedRegisterId);
        self::assertSame(['Karta'], array_map(static fn ($m): ?string => $m->name, $form->paymentMethods));
        self::assertNull($form->proposedDateClosed);

        $id = $this->close($this->manager);
        $closed = $this->form($this->employee)->proposedDateClosed;
        self::assertNotNull($closed);
        self::assertSame([$id, 'Mark', 'Manager', '2026-11-22T00:30:00+00:00'], [$closed->dayCloseId, $closed->closedByFirstName, $closed->closedByLastName, $closed->closedAt->format(\DATE_ATOM)]);
        self::assertNull($this->formHandler()(new GetDayCloseFormQuery($this->organization->getId()->toRfc4122(), $this->formerEmployee->getId()->toRfc4122())));
    }

    public function test_it_preselects_the_latest_closes_register_when_several_are_active(): void
    {
        $terrace = Register::create(Uuid::v7(), 'Terrace', null, $this->owner->getId(), MembershipRole::Owner, $this->clock->now());
        $this->registers->add($terrace);
        self::assertNull($this->form($this->employee)->preselectedRegisterId);

        $this->close($this->employee, register: $terrace);

        self::assertSame($terrace->getId()->toRfc4122(), $this->form($this->employee)->preselectedRegisterId);
    }

    public function test_it_says_the_form_cannot_close_while_no_storage_is_active(): void
    {
        foreach ([$this->box, $this->safe] as $storage) {
            $storage->deactivate($this->owner->getId(), MembershipRole::Owner, $this->clock->now());
        }

        self::assertFalse($this->form($this->employee)->canClose);
    }

    public function test_it_shows_closes_to_owners_and_managers_only(): void
    {
        $id = $this->close($this->employee, box: [[$this->hundred, 1]]);

        self::assertSame([$id], array_map(static fn (DayCloseView $view): string => $view->id, $this->list($this->manager, '2026-11-01', '2026-11-30') ?? []));
        self::assertSame([], $this->list($this->owner, '2026-11-22', '2026-11-30'));
        self::assertSame(['Eve', 'Employee'], [$this->details($id)->closedByFirstName, $this->details($id)->closedByLastName]);
        $this->expectExceptionThrown(CashFlowAccessForbiddenException::class, fn () => $this->list($this->employee, '2026-11-01', '2026-11-30'));
        $this->expectExceptionThrown(CashFlowAccessForbiddenException::class, fn () => $this->getHandler()(new GetDayCloseQuery($this->organization->getId()->toRfc4122(), $this->employee->getId()->toRfc4122(), $id)));
        $this->expectExceptionThrown(DayClosePeriodTooLongException::class, fn () => $this->list($this->manager, '2026-01-01', '2026-11-30'));
        self::assertNull($this->getHandler()(new GetDayCloseQuery($this->organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122(), Uuid::v7()->toRfc4122())));
    }

    public function test_it_shows_a_receipt_to_the_member_who_closed_and_to_managers_only(): void
    {
        $id = $this->close($this->manager);
        $other = new User(Uuid::v7(), 'emma@example.com', new \DateTimeImmutable('2026-01-01'), 'Emma', 'Other');
        OrganizationMother::addMember($this->organization, $other, MembershipRole::Employee, $this->clock->now());

        self::assertNull($this->receiptOrNull($other, $id));
        self::assertNotNull($this->receiptOrNull($this->owner, $id));
    }

    /**
     * @param list<array{Denomination, int}> $box
     * @param list<array{Denomination, int}> $safe
     */
    private function close(User $actor, array $box = [], array $safe = [], int $card = 0, ?string $date = null, ?Register $register = null): string
    {
        $command = $this->command($actor, [
            ['storageId' => $this->box->getId()->toRfc4122(), 'lines' => self::lines($box)],
            ['storageId' => $this->safe->getId()->toRfc4122(), 'lines' => self::lines($safe)],
        ], $card, $date, $register);
        $this->handle($command);

        return $command->dayCloseId;
    }

    /** @param list<array{storageId: string, lines: list<array{denominationId: string, pieces: int}>}> $storages */
    private function command(User $actor, array $storages, int $card = 0, ?string $date = null, ?Register $register = null): RecordDayCloseCommand
    {
        return new RecordDayCloseCommand(
            $this->organization->getId()->toRfc4122(),
            $actor->getId()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            ($register ?? $this->bar)->getId()->toRfc4122(),
            $storages,
            [['paymentMethodId' => $this->card->getId()->toRfc4122(), 'amount' => $card]],
            $date,
        );
    }

    private function handle(RecordDayCloseCommand $command): void
    {
        (new RecordDayCloseHandler(
            $this->closes,
            new ExpectedOpenings($this->ledger(), $this->denominations),
            $this->writer(),
            $this->offers(),
            new DayCloseRules($this->settings),
            $this->events,
            $this->queryBus,
            $this->clock,
        ))($command);
    }

    /** @param list<array{Denomination, int}> $lines */
    private function adjust(CashStorage $storage, array $lines, ?string $occurredAt = null): void
    {
        (new RecordCashAdjustmentHandler($this->writer(), $this->queryBus, $this->clock))(new RecordCashAdjustmentCommand(
            $this->organization->getId()->toRfc4122(),
            $this->manager->getId()->toRfc4122(),
            Uuid::v7()->toRfc4122(),
            $storage->getId()->toRfc4122(),
            self::lines($lines),
            null,
            $occurredAt,
        ));
        // Only the closes' events matter here.
        $this->events->events = [];
    }

    private function details(string $id): DayCloseView
    {
        $view = $this->getHandler()(new GetDayCloseQuery($this->organization->getId()->toRfc4122(), $this->owner->getId()->toRfc4122(), $id));
        self::assertNotNull($view);

        return $view;
    }

    private function receipt(User $actor, string $id): DayCloseView
    {
        $view = $this->receiptOrNull($actor, $id);
        self::assertNotNull($view);

        return $view;
    }

    private function receiptOrNull(User $actor, string $id): ?DayCloseView
    {
        return (new GetDayCloseReceiptHandler($this->closes, $this->views(), $this->queryBus))(new GetDayCloseReceiptQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id));
    }

    /** @return list<DayCloseView>|null */
    private function list(User $actor, string $from, string $to): ?array
    {
        return (new ListDayClosesHandler($this->closes, $this->views(), $this->queryBus))(new ListDayClosesQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $from, $to));
    }

    private function form(User $actor): DayCloseFormView
    {
        $form = $this->formHandler()(new GetDayCloseFormQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()));
        self::assertNotNull($form);

        return $form;
    }

    private function formHandler(): GetDayCloseFormHandler
    {
        return new GetDayCloseFormHandler($this->offers(), new DayCloseRules($this->settings), $this->closes, $this->queryBus, $this->clock);
    }

    private function getHandler(): GetDayCloseHandler
    {
        return new GetDayCloseHandler($this->closes, $this->views(), $this->queryBus);
    }

    private function ledger(): InMemoryCashLedger
    {
        return new InMemoryCashLedger($this->movements, $this->closes);
    }

    private function writer(): CashLedgerWriter
    {
        return new CashLedgerWriter($this->movements, $this->ledger(), $this->storages, $this->denominations, $this->events);
    }

    private function offers(): DayCloseOffers
    {
        return new DayCloseOffers($this->storages, $this->denominations, $this->queryBus);
    }

    private function views(): DayCloseViews
    {
        return new DayCloseViews($this->queryBus);
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

    /** @return list<array{string, string, int, ?int}> name, mode, total and expected opening per storage */
    private static function storages(DayCloseView $view): array
    {
        return array_map(static fn ($storage): array => [$storage->name, $storage->mode, $storage->total, $storage->expectedOpening], $view->storages);
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
