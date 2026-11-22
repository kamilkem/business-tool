<?php

declare(strict_types=1);

namespace App\Tests\Functional\CashFlow;

use App\CashFlow\Domain\CashLedgerInterface;
use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\DayCloseOffer;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\MovementPieces;
use App\CashFlow\Domain\Model\StorageCount;
use App\CashFlow\Domain\Repository\CashMovementRepositoryInterface;
use App\CashFlow\Domain\Repository\CashStorageRepositoryInterface;
use App\CashFlow\Domain\Repository\DayCloseRepositoryInterface;
use App\CashFlow\Domain\Repository\DenominationRepositoryInterface;
use App\CashFlow\Infrastructure\Doctrine\Orm\CashLedger;
use App\CashFlow\Infrastructure\Doctrine\Orm\Repository\DayCloseRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * End-of-day closes in the SQL ledger (CashLedger) and DayCloseRepository against PostgreSQL
 * (ADR 0033 §6): a close's count anchors a counted storage, its deposits add to a deposit storage,
 * and the storages and denominations it uses count as used.
 */
#[CoversClass(CashLedger::class)]
#[CoversClass(DayCloseRepository::class)]
final class CashLedgerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private CashLedgerInterface $ledger;
    private CashMovementRepositoryInterface $movements;
    private DayCloseRepositoryInterface $closes;
    private CashStorageRepositoryInterface $storages;
    private DenominationRepositoryInterface $denominations;
    private Uuid $owner;
    private Uuid $registerId;
    private CashStorage $box;
    private CashStorage $safe;
    private Denomination $fifty;
    private Denomination $hundred;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->tenantContext = $container->get(TenantContextInterface::class);
        $this->ledger = $container->get(CashLedgerInterface::class);
        $this->movements = $container->get(CashMovementRepositoryInterface::class);
        $this->closes = $container->get(DayCloseRepositoryInterface::class);
        $this->storages = $container->get(CashStorageRepositoryInterface::class);
        $this->denominations = $container->get(DenominationRepositoryInterface::class);

        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        $this->owner = Uuid::v7();
        $this->registerId = Uuid::v7();
        $created = new \DateTimeImmutable('2026-11-01');
        $this->box = CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $this->owner, MembershipRole::Owner, $created);
        $this->safe = CashStorage::create(Uuid::v7(), 'Sejf', CashStorageMode::Deposit, null, $this->owner, MembershipRole::Owner, $created);
        $this->fifty = Denomination::create(Uuid::v7(), 'PLN', 5_000, DenominationKind::Note, null, $this->owner, MembershipRole::Owner, $created);
        $this->hundred = Denomination::create(Uuid::v7(), 'PLN', 10_000, DenominationKind::Note, null, $this->owner, MembershipRole::Owner, $created);
        $this->storages->add($this->box);
        $this->storages->add($this->safe);
        $this->denominations->add($this->fifty);
        $this->denominations->add($this->hundred);
    }

    public function test_it_anchors_a_counted_storage_at_a_closes_count_and_adds_its_deposits(): void
    {
        $this->adjust($this->box, '2026-11-21 08:00', [[$this->hundred, 5]]);
        $this->adjust($this->safe, '2026-11-21 08:00', [[$this->fifty, 1]]);
        $close = $this->close('2026-11-21', '2026-11-22 01:00', box: [[$this->hundred, 2]], safe: [[$this->hundred, 3]]);
        $this->adjust($this->box, '2026-11-22 09:00', [[$this->fifty, 1]]);
        $this->entityManager->clear();

        $box = $this->ledger->balanceOf($this->box->getId());
        self::assertSame(self::byId([[$this->fifty, 1], [$this->hundred, 2]]), $box->pieces);
        self::assertEquals(new \DateTimeImmutable('2026-11-22 01:00'), $box->countedAt);
        self::assertSame(self::byId([[$this->fifty, 1], [$this->hundred, 3]]), $this->ledger->balanceOf($this->safe->getId())->pieces);

        // Just before the close: the adjustments only.
        self::assertSame(self::byId([[$this->hundred, 5]]), $this->ledger->balanceOf($this->box->getId(), $close->position())->pieces);

        self::assertEquals([
            LedgerEntry::count($close->position(), self::byId([[$this->fifty, 0], [$this->hundred, 2]])),
        ], \array_slice($this->ledger->entriesAfter($this->box->getId(), new LedgerPosition(new \DateTimeImmutable('2026-11-21 09:00'), Uuid::v7())), 0, 1));
        self::assertEquals([LedgerEntry::delta($close->position(), self::byId([[$this->hundred, 3]]))], $this->ledger->entriesAfter($this->safe->getId(), new LedgerPosition(new \DateTimeImmutable('2026-11-21 09:00'), Uuid::v7())));
    }

    public function test_it_finds_closes_by_date_latest_first_and_refuses_a_second_one_of_a_date(): void
    {
        $first = $this->close('2026-11-20', '2026-11-21 01:00');
        $second = $this->close('2026-11-21', '2026-11-22 01:00', box: [[$this->fifty, 1]]);

        self::assertSame($second->getId()->toRfc4122(), $this->closes->findLatest()?->getId()->toRfc4122());
        self::assertSame($first->getId()->toRfc4122(), $this->closes->findByDate(BusinessDate::fromString('2026-11-20'))?->getId()->toRfc4122());
        self::assertSame([$second->getId()->toRfc4122(), $first->getId()->toRfc4122()], array_map(static fn (DayClose $c): string => $c->getId()->toRfc4122(), $this->closes->findInPeriod(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'))));
        self::assertSame(5_000, $this->closes->findById($second->getId())?->getCashIncome());

        $this->expectException(DayAlreadyClosedException::class);
        $this->close('2026-11-21', '2026-11-22 02:00');
    }

    public function test_it_tells_that_a_close_uses_its_storages_and_denominations_and_keeps_them_from_another_organization(): void
    {
        $this->close('2026-11-21', '2026-11-22 01:00', safe: [[$this->hundred, 1]]);
        $this->entityManager->clear();

        self::assertTrue($this->storages->isUsed($this->box->getId()));
        self::assertTrue($this->storages->isUsed($this->safe->getId()));
        self::assertTrue($this->denominations->isUsed($this->hundred->getId()));
        // A counted storage's count has a line for every active denomination.
        self::assertTrue($this->denominations->isUsed($this->fifty->getId()));

        $this->tenantContext->switchTo(OrganizationFactory::createOne());
        self::assertNull($this->closes->findLatest());
        self::assertSame([], $this->ledger->balanceOf($this->box->getId())->pieces);
        self::assertSame([], $this->storages->usedIds());
    }

    /**
     * @param list<array{Denomination, int}> $box
     * @param list<array{Denomination, int}> $safe
     */
    private function close(string $date, string $at, array $box = [], array $safe = []): DayClose
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable($at);
        $boxStorage = $this->managed($this->box);
        $safeStorage = $this->managed($this->safe);
        $offer = new DayCloseOffer('PLN', [$boxStorage, $safeStorage], $this->denominations->findByCurrency('PLN'), [$this->registerId->toRfc4122() => 'Bar'], []);
        $opening = 0;
        foreach ($this->ledger->balanceOf($boxStorage->getId(), DayClose::positionAt($id, $now))->pieces as $denominationId => $pieces) {
            $opening += $pieces * ($this->denominations->findById(Uuid::fromString((string) $denominationId))?->getValue() ?? 0);
        }

        $close = DayClose::record(
            $id,
            BusinessDate::fromString($date),
            $offer,
            $this->registerId->toRfc4122(),
            [new StorageCount($boxStorage, $this->pieces($box)), new StorageCount($safeStorage, $this->pieces($safe))],
            [],
            [$boxStorage->getId()->toRfc4122() => $opening],
            null,
            null,
            $this->owner,
            $now,
        );
        $this->closes->add($close);

        return $close;
    }

    /** @param list<array{Denomination, int}> $lines */
    private function adjust(CashStorage $storage, string $at, array $lines): void
    {
        $this->movements->add(CashMovement::adjust(Uuid::v7(), $this->managed($storage), $this->pieces($lines), 'PLN', null, new \DateTimeImmutable($at), $this->owner, MembershipRole::Owner, new \DateTimeImmutable('2026-11-30')));
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
     * @return array<string, int>
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
}
