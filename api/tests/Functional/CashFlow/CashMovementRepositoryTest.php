<?php

declare(strict_types=1);

namespace App\Tests\Functional\CashFlow;

use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\MovementPieces;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\CashFlow\Infrastructure\Doctrine\Orm\Repository\CashMovementRepository;
use App\CashFlow\Infrastructure\Doctrine\Orm\Repository\CashStorageRepository;
use App\CashFlow\Infrastructure\Doctrine\Orm\Repository\DenominationRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\UtcRange;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The ledger SQL of CashMovementRepository against PostgreSQL (ADR 0033 §4): balances from the last
 * count, ledger order, the entries after a position, the history, the TenantFilter, and what the
 * storages and denominations know of their use.
 */
#[CoversClass(CashMovementRepository::class)]
#[CoversClass(CashStorageRepository::class)]
#[CoversClass(DenominationRepository::class)]
final class CashMovementRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private CashMovementRepositoryInterface $movements;
    private CashStorageRepositoryInterface $storages;
    private DenominationRepositoryInterface $denominations;
    private Uuid $owner;
    private CashStorage $safe;
    private CashStorage $box;
    private Denomination $fifty;
    private Denomination $hundred;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->movements = self::getContainer()->get(CashMovementRepositoryInterface::class);
        $this->storages = self::getContainer()->get(CashStorageRepositoryInterface::class);
        $this->denominations = self::getContainer()->get(DenominationRepositoryInterface::class);

        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->owner = Uuid::v7();
        [$this->safe, $this->box, $this->fifty, $this->hundred] = $this->addStoragesAndDenominations();
    }

    public function test_it_derives_a_balance_from_the_last_count_and_the_signed_lines_after_it(): void
    {
        $this->add($this->adjust($this->safe, '08:00', [[$this->hundred, 5], [$this->fifty, 3]]));
        $count = $this->add($this->auditCount($this->safe, '09:00', [[$this->hundred, 2]]));
        $this->add($this->adjust($this->safe, '10:00', [[$this->hundred, 1], [$this->fifty, 4]]));
        $this->add($this->adjust($this->safe, '11:00', [[$this->fifty, -1]]));
        $this->entityManager->clear();

        $balance = $this->movements->balanceOf($this->safe->getId());
        self::assertSame(self::byId([[$this->fifty, 3], [$this->hundred, 3]]), $balance->pieces);
        self::assertEquals(self::at('09:00'), $balance->countedAt);

        // Just before the count: everything before it; at 10:00 (exclusive): the count only.
        $before = $this->movements->balanceOf($this->safe->getId(), $count->position());
        self::assertSame(self::byId([[$this->fifty, 3], [$this->hundred, 5]]), $before->pieces);
        self::assertNull($before->countedAt);
        self::assertSame(self::byId([[$this->hundred, 2]]), $this->movements->balanceOf($this->safe->getId(), new LedgerPosition(self::at('10:00'), Uuid::fromString('00000000-0000-7000-8000-000000000000')))->pieces);

        self::assertSame([], $this->movements->balanceOf($this->box->getId())->pieces);
    }

    public function test_it_orders_the_ledger_by_moment_then_by_id_and_lists_the_entries_after_a_position(): void
    {
        $first = $this->add($this->adjust($this->safe, '10:00', [[$this->hundred, 1]], id: '01900000-0000-7000-8000-000000000002'));
        $sameMomentLowerId = $this->add($this->auditCount($this->safe, '10:00', [[$this->hundred, 7]], id: '01900000-0000-7000-8000-000000000001'));
        // Entered last, backdated before both.
        $backdated = $this->add($this->transfer($this->box, $this->safe, '09:00', [[$this->fifty, 2]]));
        $this->entityManager->clear();

        // The count (lower id) comes first at 10:00, so the adjustment adds to it.
        self::assertSame(self::byId([[$this->hundred, 8]]), $this->movements->balanceOf($this->safe->getId())->pieces);

        $after = $this->movements->entriesAfter($this->safe->getId(), $backdated->position());
        self::assertEquals([
            LedgerEntry::count($sameMomentLowerId->position(), self::byId([[$this->fifty, 0], [$this->hundred, 7]])),
            LedgerEntry::delta($first->position(), self::byId([[$this->hundred, 1]])),
        ], $after);
        self::assertEquals([LedgerEntry::delta($backdated->position(), self::byId([[$this->fifty, -2]]))], $this->movements->entriesAfter($this->box->getId(), new LedgerPosition(self::at('08:00'), Uuid::v7())));
    }

    public function test_it_lists_the_movements_of_a_range_newest_first_optionally_on_one_storage(): void
    {
        $early = $this->add($this->adjust($this->box, '07:59', [[$this->fifty, 1]]));
        $transfer = $this->add($this->transfer($this->box, $this->safe, '08:00', [[$this->fifty, 1]]));
        $boxOnly = $this->add($this->adjust($this->box, '09:00', [[$this->hundred, 1]]));
        $this->add($this->adjust($this->box, '10:00', [[$this->hundred, 1]]));
        $this->entityManager->clear();

        $range = new UtcRange(self::at('08:00'), self::at('10:00'));
        self::assertSame([$boxOnly->getId()->toRfc4122(), $transfer->getId()->toRfc4122()], self::ids($this->movements->findOccurredIn($range)));
        self::assertSame([$transfer->getId()->toRfc4122()], self::ids($this->movements->findOccurredIn($range, $this->safe->getId())));
        // Both lines of the transfer are loaded, whichever storage it was found by.
        self::assertCount(2, $this->movements->findOccurredIn($range, $this->safe->getId())[0]->getLines());
        self::assertNotContains($early->getId()->toRfc4122(), self::ids($this->movements->findOccurredIn($range)));
    }

    public function test_it_keeps_another_organizations_ledger_out(): void
    {
        $this->add($this->adjust($this->safe, '10:00', [[$this->hundred, 1]]));
        $theirs = $this->movements->findOccurredIn(new UtcRange(self::at('00:00'), self::at('23:59')))[0];
        $this->entityManager->clear();

        $this->tenantContext->switchTo(OrganizationFactory::createOne());

        self::assertNull($this->movements->findById($theirs->getId()));
        self::assertSame([], $this->movements->findOccurredIn(new UtcRange(self::at('00:00'), self::at('23:59'))));
        self::assertSame([], $this->movements->balanceOf($this->safe->getId())->pieces);
        self::assertSame([], $this->movements->entriesAfter($this->safe->getId(), new LedgerPosition(self::at('00:00'), Uuid::v7())));
        self::assertSame([], $this->storages->usedIds());
        self::assertSame([], $this->denominations->findByIds([$this->hundred->getId()->toRfc4122()]));
    }

    public function test_it_tells_which_storages_and_denominations_movements_use(): void
    {
        $movement = $this->add($this->adjust($this->safe, '10:00', [[$this->hundred, 1]]));
        $this->entityManager->clear();

        self::assertTrue($this->storages->isUsed($this->safe->getId()));
        self::assertFalse($this->storages->isUsed($this->box->getId()));
        self::assertSame([$this->safe->getId()->toRfc4122()], $this->storages->usedIds());
        self::assertTrue($this->denominations->isUsed($this->hundred->getId()));
        self::assertFalse($this->denominations->isUsed($this->fifty->getId()));
        self::assertSame([$this->hundred->getId()->toRfc4122()], $this->denominations->usedIds());

        $this->movements->remove($this->movements->findById($movement->getId()) ?? throw new \LogicException('Movement expected.'));
        self::assertFalse($this->storages->isUsed($this->safe->getId()));
        self::assertSame([], $this->denominations->usedIds());
    }

    public function test_it_keeps_a_storage_that_a_movement_started_using_in_the_meantime(): void
    {
        $this->add($this->adjust($this->safe, '10:00', [[$this->hundred, 1]]));
        $this->entityManager->clear();

        // A use the delete's check did not see: the foreign key keeps the storage.
        $this->expectException(StorageInUseException::class);

        $this->storages->remove($this->storages->findById($this->safe->getId()) ?? throw new \LogicException('Storage expected.'));
    }

    /** @return array{CashStorage, CashStorage, Denomination, Denomination} */
    private function addStoragesAndDenominations(): array
    {
        $created = new \DateTimeImmutable('2026-11-01');
        $safe = CashStorage::create(Uuid::v7(), 'Sejf', CashStorageMode::Deposit, null, $this->owner, MembershipRole::Owner, $created);
        $box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $this->owner, MembershipRole::Owner, $created);
        $fifty = Denomination::create(Uuid::v7(), 'PLN', 5_000, DenominationKind::Note, null, $this->owner, MembershipRole::Owner, $created);
        $hundred = Denomination::create(Uuid::v7(), 'PLN', 10_000, DenominationKind::Note, null, $this->owner, MembershipRole::Owner, $created);
        $this->storages->add($safe);
        $this->storages->add($box);
        $this->denominations->add($fifty);
        $this->denominations->add($hundred);

        return [$safe, $box, $fifty, $hundred];
    }

    private function add(CashMovement $movement): CashMovement
    {
        $this->movements->add($movement);

        return $movement;
    }

    /** @param list<array{Denomination, int}> $lines */
    private function adjust(CashStorage $storage, string $time, array $lines, ?string $id = null): CashMovement
    {
        return CashMovement::adjust($id ? Uuid::fromString($id) : Uuid::v7(), $this->managed($storage), $this->pieces($lines), 'PLN', null, self::at($time), $this->owner, MembershipRole::Owner, self::at('23:59'));
    }

    /** @param list<array{Denomination, int}> $lines */
    private function transfer(CashStorage $from, CashStorage $to, string $time, array $lines): CashMovement
    {
        return CashMovement::transfer(Uuid::v7(), $this->managed($from), $this->managed($to), $this->pieces($lines), 'PLN', null, self::at($time), $this->owner, MembershipRole::Owner, self::at('23:59'));
    }

    /** @param list<array{Denomination, int}> $lines */
    private function auditCount(CashStorage $storage, string $time, array $lines, ?string $id = null): CashMovement
    {
        return CashMovement::count($id ? Uuid::fromString($id) : Uuid::v7(), $this->managed($storage), $this->pieces($lines), $this->denominations->findByCurrency('PLN'), 'PLN', null, self::at($time), $this->owner, MembershipRole::Owner, self::at('23:59'));
    }

    private function managed(CashStorage $storage): CashStorage
    {
        return $this->storages->findById($storage->getId()) ?? throw new \LogicException('Storage expected.');
    }

    /**
     * @param list<array{Denomination, int}> $lines
     *
     * @return list<MovementPieces>
     */
    private function pieces(array $lines): array
    {
        return array_map(fn (array $line): MovementPieces => new MovementPieces(
            $this->denominations->findById($line[0]->getId()) ?? throw new \LogicException('Denomination expected.'),
            $line[1],
        ), $lines);
    }

    /**
     * @param list<array{Denomination, int}> $lines
     *
     * @return array<string, int> pieces by denomination id, sorted by id as the repository returns them
     */
    private static function byId(array $lines): array
    {
        $pieces = [];
        foreach ($lines as [$denomination, $count]) {
            $pieces[$denomination->getId()->toRfc4122()] = $count;
        }
        ksort($pieces);

        return $pieces;
    }

    /**
     * @param list<CashMovement> $movements
     *
     * @return list<string>
     */
    private static function ids(array $movements): array
    {
        return array_map(static fn (CashMovement $movement): string => $movement->getId()->toRfc4122(), $movements);
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-21 '.$time.':00', new \DateTimeZone('UTC'));
    }
}
