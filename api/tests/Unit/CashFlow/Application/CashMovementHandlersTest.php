<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Application;

use App\CashFlow\Application\CashLedgerWriter;
use App\CashFlow\Application\CashMovementViews;
use App\CashFlow\Application\Command\DeleteCashMovement\DeleteCashMovementCommand;
use App\CashFlow\Application\Command\DeleteCashMovement\DeleteCashMovementHandler;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentCommand;
use App\CashFlow\Application\Command\RecordCashAdjustment\RecordCashAdjustmentHandler;
use App\CashFlow\Application\Command\RecordCashCount\RecordCashCountCommand;
use App\CashFlow\Application\Command\RecordCashCount\RecordCashCountHandler;
use App\CashFlow\Application\Command\RecordCashTransfer\RecordCashTransferCommand;
use App\CashFlow\Application\Command\RecordCashTransfer\RecordCashTransferHandler;
use App\CashFlow\Application\Query\GetCashMovement\CashMovementView;
use App\CashFlow\Application\Query\GetCashMovement\GetCashMovementHandler;
use App\CashFlow\Application\Query\GetCashMovement\GetCashMovementQuery;
use App\CashFlow\Application\Query\ListCashBalances\CashBalanceLineView;
use App\CashFlow\Application\Query\ListCashBalances\CashBalanceView;
use App\CashFlow\Application\Query\ListCashBalances\ListCashBalancesHandler;
use App\CashFlow\Application\Query\ListCashBalances\ListCashBalancesQuery;
use App\CashFlow\Application\Query\ListCashMovements\ListCashMovementsHandler;
use App\CashFlow\Application\Query\ListCashMovements\ListCashMovementsQuery;
use App\CashFlow\Domain\Event\CashLedgerChanged;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationNotFoundException;
use App\CashFlow\Domain\Exception\MovementNotFoundException;
use App\CashFlow\Domain\Exception\MovementPeriodTooLongException;
use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Exception\StorageNotFoundException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\Organization\Application\Query\GetMember\GetMemberHandler;
use App\Organization\Application\Query\GetMember\GetMemberQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationHandler;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\ListMembers\ListMembersHandler;
use App\Organization\Application\Query\ListMembers\ListMembersQuery;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Shared\Application\Query\QueryInterface;
use App\Tests\Fixtures\OrganizationMother;
use App\Tests\Fixtures\UserMother;
use App\Tests\Unit\Organization\Application\InMemoryOrganizationRepository;
use App\Tests\Unit\Shared\Application\RecordingEventPublisher;
use App\Tests\Unit\Shared\Application\StubQueryBus;
use App\User\Domain\Model\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * A Polish organization (PLN, Europe/Warsaw) with a counted cash box, a deposit safe and three
 * notes, and an owner, a manager, an employee and a former manager. The time is NOW (UTC).
 */
#[CoversClass(CashLedgerWriter::class)]
#[CoversClass(CashMovementViews::class)]
#[CoversClass(RecordCashAdjustmentHandler::class)]
#[CoversClass(RecordCashTransferHandler::class)]
#[CoversClass(RecordCashCountHandler::class)]
#[CoversClass(DeleteCashMovementHandler::class)]
#[CoversClass(GetCashMovementHandler::class)]
#[CoversClass(ListCashMovementsHandler::class)]
#[CoversClass(ListCashBalancesHandler::class)]
final class CashMovementHandlersTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private MockClock $clock;
    private InMemoryCashStorageRepository $storages;
    private InMemoryDenominationRepository $denominations;
    private InMemoryCashMovementRepository $movements;
    private RecordingEventPublisher $events;
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

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $organizations = new InMemoryOrganizationRepository();
        $this->storages = new InMemoryCashStorageRepository();
        $this->denominations = new InMemoryDenominationRepository();
        $this->movements = new InMemoryCashMovementRepository();
        $this->events = new RecordingEventPublisher();
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

        $this->queryBus = new StubQueryBus(static fn (QueryInterface $query): mixed => match (true) {
            $query instanceof GetMemberQuery => (new GetMemberHandler($organizations))($query),
            $query instanceof GetOrganizationQuery => (new GetOrganizationHandler($organizations))($query),
            $query instanceof ListMembersQuery => (new ListMembersHandler($organizations))($query),
            default => throw new \LogicException(\sprintf('Unexpected query %s.', $query::class)),
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
    }

    public function test_it_records_an_adjustment_and_derives_the_storages_balance_from_it(): void
    {
        $id = $this->adjust($this->manager, $this->safe, [[$this->hundred, 10], [$this->fifty, 4]], note: 'Opening float');

        $movement = $this->found($this->owner, $id);
        self::assertSame(['adjustment', 'Sejf', null, 120_000, null, 'Opening float', 'Mark', 'Manager'], [$movement->kind, $movement->storageName, $movement->targetStorageId, $movement->total, $movement->discrepancy, $movement->note, $movement->createdByFirstName, $movement->createdByLastName]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $movement->occurredAt);

        $safe = $this->balanceOf($this->safe);
        self::assertSame([[5_000, 4], [10_000, 10], [20_000, 0]], self::pieces($safe));
        self::assertSame([120_000, 'PLN', false, null], [$safe->total, $safe->currency, $safe->estimate, $safe->countedAt]);
        self::assertTrue($this->balanceOf($this->box)->estimate);

        $event = $this->events->events[0] ?? null;
        self::assertInstanceOf(CashLedgerChanged::class, $event);
        self::assertSame([$this->organization->getId()->toRfc4122(), $id, [$this->safe->getId()->toRfc4122()], '2026-11-22T10:00:00+00:00', $this->manager->getId()->toRfc4122()], [$event->organizationId, $event->movementId, $event->storageIds, $event->occurredAt, $event->actorId]);
    }

    public function test_it_moves_cash_between_storages_in_one_movement(): void
    {
        $this->adjust($this->owner, $this->box, [[$this->hundred, 5]]);

        $id = $this->transfer($this->manager, $this->box, $this->safe, [[$this->hundred, 3]]);

        $movement = $this->found($this->manager, $id);
        self::assertSame(['transfer', 'Kasetka', 'Sejf', 30_000], [$movement->kind, $movement->storageName, $movement->targetStorageName, $movement->total]);
        self::assertSame([[10_000, 3]], array_map(static fn ($line): array => [$line->value, $line->pieces], $movement->lines));
        self::assertSame(20_000, $this->balanceOf($this->box)->total);
        self::assertSame(30_000, $this->balanceOf($this->safe)->total);
        self::assertSame([$this->box->getId()->toRfc4122(), $this->safe->getId()->toRfc4122()], $this->lastEvent()->storageIds);
    }

    public function test_it_refuses_to_take_more_out_of_a_deposit_storage_than_it_holds_and_stores_nothing(): void
    {
        $this->adjust($this->owner, $this->safe, [[$this->hundred, 2]]);

        try {
            $this->transfer($this->owner, $this->safe, $this->box, [[$this->hundred, 3]]);
            self::fail('The safe should not go below zero.');
        } catch (StorageBalanceNegativeException $e) {
            self::assertSame(['name' => 'Sejf', 'value' => 10_000, 'pieces' => -1], array_intersect_key($e->errorParameters(), ['name' => true, 'value' => true, 'pieces' => true]));
        }

        self::assertSame(20_000, $this->balanceOf($this->safe)->total);
        self::assertSame(0, $this->balanceOf($this->box)->total);
        self::assertCount(1, $this->events->events);
    }

    public function test_it_does_not_check_a_counted_storage_between_closes(): void
    {
        // A 200 note taken out of the cash box in the evening: the day's takings are in it, uncounted.
        $this->adjust($this->manager, $this->box, [[$this->twoHundred, -1]]);

        self::assertSame([[5_000, 0], [10_000, 0], [20_000, -1]], self::pieces($this->balanceOf($this->box)));
        self::assertSame(-20_000, $this->balanceOf($this->box)->total);
    }

    public function test_it_refuses_a_backdated_withdrawal_that_a_later_one_would_then_lack(): void
    {
        $this->clock->modify('2026-11-20 08:00:00');
        $this->adjust($this->owner, $this->safe, [[$this->hundred, 5]]);
        $this->clock->modify('2026-11-21 12:00:00');
        $this->adjust($this->owner, $this->safe, [[$this->hundred, -4]], note: 'Bank run');
        $this->clock->modify(self::NOW);

        // 2 notes out on the 20th at 18:00 (Warsaw): 3 are left then, but the bank run lacks one.
        try {
            $this->adjust($this->owner, $this->safe, [[$this->hundred, -2]], occurredAt: '2026-11-20T18:00:00+01:00');
            self::fail('The later bank run should go below zero.');
        } catch (StorageBalanceNegativeException $e) {
            self::assertSame(['pieces' => -1, 'at' => '2026-11-21T12:00:00+00:00'], array_intersect_key($e->errorParameters(), ['pieces' => true, 'at' => true]));
        }

        // One note backdated is covered.
        $id = $this->adjust($this->owner, $this->safe, [[$this->hundred, -1]], occurredAt: '2026-11-20T18:00:00+01:00');
        self::assertSame('2026-11-20T17:00:00+00:00', $this->found($this->owner, $id)->occurredAt->format(\DATE_ATOM));
        self::assertSame('2026-11-20T17:00:00+00:00', $this->lastEvent()->occurredAt);
        self::assertSame(0, $this->balanceOf($this->safe)->total);
    }

    public function test_it_takes_an_audit_count_as_the_new_balance_and_derives_its_discrepancy(): void
    {
        $this->clock->modify('2026-11-21 09:00:00');
        $this->adjust($this->owner, $this->safe, [[$this->hundred, 5], [$this->fifty, 2]]);
        $this->clock->modify(self::NOW);

        $id = $this->auditCount($this->manager, $this->safe, [[$this->hundred, 4]]);

        $count = $this->found($this->owner, $id);
        self::assertSame(['count', 40_000, -10_000 - 10_000], [$count->kind, $count->total, $count->discrepancy]);
        self::assertSame([[5_000, 0], [10_000, 4], [20_000, 0]], array_map(static fn ($line): array => [$line->value, $line->pieces], $count->lines));
        $safe = $this->balanceOf($this->safe);
        self::assertSame(40_000, $safe->total);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $safe->countedAt);

        // A later adjustment counts from the count on.
        $this->adjust($this->owner, $this->safe, [[$this->fifty, 1]]);
        self::assertSame(45_000, $this->balanceOf($this->safe)->total);
    }

    public function test_it_deletes_a_movement_unless_a_deposit_storage_would_go_below_zero_without_it(): void
    {
        $float = $this->adjust($this->owner, $this->safe, [[$this->hundred, 3]]);
        $this->clock->modify('+1 hour');
        $out = $this->adjust($this->owner, $this->safe, [[$this->hundred, -2]]);

        try {
            ($this->deleteHandler())(new DeleteCashMovementCommand($this->organization->getId()->toRfc4122(), $this->manager->getId()->toRfc4122(), $float));
            self::fail('Without the float, the withdrawal should go below zero.');
        } catch (StorageBalanceNegativeException $e) {
            self::assertSame(-2, $e->errorParameters()['pieces']);
        }
        self::assertNotNull($this->movements->findById(Uuid::fromString($float)));

        ($this->deleteHandler())(new DeleteCashMovementCommand($this->organization->getId()->toRfc4122(), $this->manager->getId()->toRfc4122(), $out));
        self::assertNull($this->movements->findById(Uuid::fromString($out)));
        self::assertSame(30_000, $this->balanceOf($this->safe)->total);
        self::assertSame($out, $this->lastEvent()->movementId);
    }

    public function test_it_answers_not_found_for_a_missing_movement_storage_or_denomination(): void
    {
        $organizationId = $this->organization->getId()->toRfc4122();
        $ownerId = $this->owner->getId()->toRfc4122();

        self::assertNull($this->get($this->owner, Uuid::v7()->toRfc4122()));
        self::assertNull($this->get($this->owner, 'not-a-uuid'));

        $this->expectExceptionThrown(MovementNotFoundException::class, fn () => ($this->deleteHandler())(new DeleteCashMovementCommand($organizationId, $ownerId, Uuid::v7()->toRfc4122())));
        $this->expectExceptionThrown(StorageNotFoundException::class, fn () => ($this->adjustHandler())(new RecordCashAdjustmentCommand($organizationId, $ownerId, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), [['denominationId' => $this->hundred->getId()->toRfc4122(), 'pieces' => 1]])));
        $this->expectExceptionThrown(DenominationNotFoundException::class, fn () => ($this->adjustHandler())(new RecordCashAdjustmentCommand($organizationId, $ownerId, Uuid::v7()->toRfc4122(), $this->safe->getId()->toRfc4122(), [['denominationId' => Uuid::v7()->toRfc4122(), 'pieces' => 1]])));
        $this->expectExceptionThrown(StorageNotFoundException::class, fn () => $this->list($this->owner, '2026-11-01', '2026-11-30', Uuid::v7()->toRfc4122()));
    }

    public function test_it_lets_only_active_owners_and_managers_move_cash_and_read_the_ledger(): void
    {
        $id = $this->adjust($this->owner, $this->safe, [[$this->hundred, 1]]);

        $this->expectExceptionThrown(CashFlowAccessForbiddenException::class, fn () => $this->adjust($this->employee, $this->safe, [[$this->hundred, 1]]));
        $this->expectExceptionThrown(CashFlowAccessForbiddenException::class, fn () => ($this->deleteHandler())(new DeleteCashMovementCommand($this->organization->getId()->toRfc4122(), $this->employee->getId()->toRfc4122(), $id)));
        $this->expectExceptionThrown(OrganizationNotFoundException::class, fn () => $this->adjust($this->formerManager, $this->safe, [[$this->hundred, 1]]));

        // Employees see only what they enter: no balance, no history.
        foreach ([
            fn () => $this->balances($this->employee),
            fn () => $this->list($this->employee, '2026-11-01', '2026-11-30'),
            fn () => $this->get($this->employee, $id),
        ] as $read) {
            try {
                $read();
                self::fail('An employee should not read the ledger.');
            } catch (CashFlowAccessForbiddenException $e) {
                self::assertSame('cash_flow.access_forbidden.to_read_ledger', $e->errorCode());
            }
        }

        self::assertNull($this->balances($this->formerManager));
        self::assertNull($this->list($this->formerManager, '2026-11-01', '2026-11-30'));
        self::assertNull($this->get($this->formerManager, $id));
    }

    public function test_it_lists_the_history_of_a_period_in_the_organizations_time_zone_newest_first(): void
    {
        // 2026-11-30 23:30 UTC is already 1 December in Warsaw.
        $this->clock->modify('2026-10-31 23:30:00');
        $novemberFirst = $this->adjust($this->owner, $this->safe, [[$this->hundred, 5]]);
        $this->clock->modify('2026-11-15 12:00:00');
        $transfer = $this->transfer($this->owner, $this->safe, $this->box, [[$this->hundred, 1]]);
        $boxOnly = $this->adjust($this->owner, $this->box, [[$this->fifty, 2]]);
        $this->clock->modify('2026-11-30 23:30:00');
        $this->adjust($this->owner, $this->safe, [[$this->fifty, 1]]);

        self::assertSame([$boxOnly, $transfer, $novemberFirst], array_map(static fn (CashMovementView $view): string => $view->id, $this->list($this->manager, '2026-11-01', '2026-11-30') ?? []));
        self::assertSame([$transfer, $novemberFirst], array_map(static fn (CashMovementView $view): string => $view->id, $this->list($this->manager, '2026-11-01', '2026-11-30', $this->safe->getId()->toRfc4122()) ?? []));

        $this->expectException(MovementPeriodTooLongException::class);
        $this->list($this->manager, '2026-01-01', '2026-12-31');
    }

    public function test_it_lists_every_active_storage_and_a_deactivated_one_only_while_it_holds_cash(): void
    {
        $old = CashStorage::create(Uuid::v7(), 'Stary sejf', CashStorageMode::Deposit, null, $this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable('2026-01-05'));
        $this->storages->add($old);
        $this->adjust($this->owner, $old, [[$this->hundred, 2]]);
        $old->deactivate($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
        $this->fifty->deactivate($this->owner->getId(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        self::assertSame(['Kasetka', 'Sejf', 'Stary sejf'], array_map(static fn (CashBalanceView $view): string => $view->name, $this->balances($this->owner) ?? []));
        // A deactivated denomination is listed only where there are pieces of it.
        self::assertSame([[10_000, 2], [20_000, 0]], self::pieces($this->balanceOf($old)));

        $this->transfer($this->owner, $old, $this->safe, [[$this->hundred, 2]]);
        self::assertSame(['Kasetka', 'Sejf'], array_map(static fn (CashBalanceView $view): string => $view->name, $this->balances($this->owner) ?? []));
    }

    /** @param list<array{Denomination, int}> $lines */
    private function adjust(User $actor, CashStorage $storage, array $lines, ?string $note = null, ?string $occurredAt = null): string
    {
        $id = Uuid::v7()->toRfc4122();
        ($this->adjustHandler())(new RecordCashAdjustmentCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $storage->getId()->toRfc4122(), self::lines($lines), $note, $occurredAt));

        return $id;
    }

    /** @param list<array{Denomination, int}> $lines */
    private function transfer(User $actor, CashStorage $from, CashStorage $to, array $lines): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new RecordCashTransferHandler($this->writer(), $this->queryBus, $this->clock))(new RecordCashTransferCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $from->getId()->toRfc4122(), $to->getId()->toRfc4122(), self::lines($lines)));

        return $id;
    }

    /** @param list<array{Denomination, int}> $lines */
    private function auditCount(User $actor, CashStorage $storage, array $lines): string
    {
        $id = Uuid::v7()->toRfc4122();
        (new RecordCashCountHandler($this->writer(), $this->denominations, $this->queryBus, $this->clock))(new RecordCashCountCommand($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id, $storage->getId()->toRfc4122(), self::lines($lines)));

        return $id;
    }

    private function get(User $actor, string $id): ?CashMovementView
    {
        return (new GetCashMovementHandler($this->movements, $this->views(), $this->queryBus))(new GetCashMovementQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $id));
    }

    private function found(User $actor, string $id): CashMovementView
    {
        return $this->get($actor, $id) ?? throw new \LogicException('Movement expected.');
    }

    /** @return list<CashMovementView>|null */
    private function list(User $actor, string $from, string $to, ?string $storageId = null): ?array
    {
        return (new ListCashMovementsHandler($this->movements, $this->storages, $this->views(), $this->queryBus))(new ListCashMovementsQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122(), $from, $to, $storageId));
    }

    /** @return list<CashBalanceView>|null */
    private function balances(User $actor): ?array
    {
        return (new ListCashBalancesHandler($this->storages, $this->denominations, $this->movements, $this->queryBus))(new ListCashBalancesQuery($this->organization->getId()->toRfc4122(), $actor->getId()->toRfc4122()));
    }

    private function balanceOf(CashStorage $storage): CashBalanceView
    {
        foreach ($this->balances($this->owner) ?? [] as $view) {
            if ($view->storageId === $storage->getId()->toRfc4122()) {
                return $view;
            }
        }

        throw new \LogicException('Balance expected.');
    }

    /** @return list<array{int, int}> value and pieces per line */
    private static function pieces(CashBalanceView $view): array
    {
        return array_map(static fn (CashBalanceLineView $line): array => [$line->value, $line->pieces], $view->lines);
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

    private function lastEvent(): CashLedgerChanged
    {
        $event = $this->events->events[\count($this->events->events) - 1] ?? null;
        self::assertInstanceOf(CashLedgerChanged::class, $event);

        return $event;
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
            self::assertInstanceOf($exception, $e);
        }
    }

    private function writer(): CashLedgerWriter
    {
        return new CashLedgerWriter($this->movements, $this->storages, $this->denominations, $this->events);
    }

    private function views(): CashMovementViews
    {
        return new CashMovementViews($this->movements, $this->denominations, $this->queryBus);
    }

    private function adjustHandler(): RecordCashAdjustmentHandler
    {
        return new RecordCashAdjustmentHandler($this->writer(), $this->queryBus, $this->clock);
    }

    private function deleteHandler(): DeleteCashMovementHandler
    {
        return new DeleteCashMovementHandler($this->movements, $this->writer(), $this->queryBus);
    }
}
