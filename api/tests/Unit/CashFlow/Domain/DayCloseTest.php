<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\DayAlreadyClosedException;
use App\CashFlow\Domain\Exception\DayCloseOutOfOrderException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidDayCloseException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\ClosedDay;
use App\CashFlow\Domain\Model\DayClose;
use App\CashFlow\Domain\Model\DayCloseOffer;
use App\CashFlow\Domain\Model\DayCloseStorage;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\MovementPieces;
use App\CashFlow\Domain\Model\StorageCount;
use App\Income\Domain\Model\RevenueEntry;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A counted cash box and a deposit safe, PLN notes of 50, 100 and 200 zł, one register and two
 * non-cash payment methods (card, BLIK). The close is made at NOW.
 */
#[CoversClass(DayClose::class)]
#[CoversClass(DayCloseStorage::class)]
#[CoversClass(DayCloseOffer::class)]
final class DayCloseTest extends TestCase
{
    private const string NOW = '2026-11-22 01:30:15.700';

    private Uuid $actor;
    private Uuid $registerId;
    private Uuid $cardId;
    private Uuid $blikId;
    private CashStorage $box;
    private CashStorage $safe;
    private Denomination $fifty;
    private Denomination $hundred;
    private Denomination $twoHundred;

    protected function setUp(): void
    {
        $this->actor = Uuid::v7();
        $this->registerId = Uuid::v7();
        $this->cardId = Uuid::v7();
        $this->blikId = Uuid::v7();
        $this->box = $this->storage('Kasetka', CashStorageMode::Counted);
        $this->safe = $this->storage('Sejf', CashStorageMode::Deposit);
        $this->fifty = $this->note(5_000);
        $this->hundred = $this->note(10_000);
        $this->twoHundred = $this->note(20_000);
    }

    public function test_it_computes_the_cash_income_as_the_count_minus_the_expected_opening_plus_the_deposits(): void
    {
        $close = $this->record(
            box: [[$this->hundred, 6], [$this->fifty, 2]],
            safe: [[$this->twoHundred, 3]],
            expectedOpenings: [$this->box->getId()->toRfc4122() => 30_000],
        );

        // Box: 70 000 counted − 30 000 expected = 40 000; safe: 60 000 deposited.
        self::assertSame(100_000, $close->getCashIncome());
        self::assertFalse($close->isShortage());
        self::assertSame([['Kasetka', 'counted', 70_000, 30_000, 40_000], ['Sejf', 'deposit', 60_000, null, 60_000]], self::storages($close));
    }

    public function test_it_takes_the_whole_count_as_income_at_the_first_close_of_an_empty_storage(): void
    {
        $close = $this->record(box: [[$this->hundred, 3]], safe: [], expectedOpenings: [$this->box->getId()->toRfc4122() => 0]);

        self::assertSame(30_000, $close->getCashIncome());
        self::assertSame([['Kasetka', 'counted', 30_000, 0, 30_000], ['Sejf', 'deposit', 0, null, 0]], self::storages($close));
    }

    public function test_it_saves_a_negative_cash_income_and_flags_it_as_a_shortage(): void
    {
        $close = $this->record(box: [[$this->hundred, 1]], safe: [[$this->fifty, 1]], expectedOpenings: [$this->box->getId()->toRfc4122() => 50_000]);

        // 10 000 − 50 000 + 5 000.
        self::assertSame(-35_000, $close->getCashIncome());
        self::assertTrue($close->isShortage());
    }

    public function test_it_adds_up_several_counted_and_deposit_storages(): void
    {
        $bar = $this->storage('Bar', CashStorageMode::Counted);
        $bag = $this->storage('Bank bag', CashStorageMode::Deposit);
        $offer = $this->offer(storages: [$bag, $bar, $this->box, $this->safe]);

        $close = DayClose::record(
            Uuid::v7(),
            BusinessDate::fromString('2026-11-21'),
            $offer,
            $this->registerId->toRfc4122(),
            [
                $this->entered($this->box, [[$this->hundred, 2]]),
                $this->entered($bar, [[$this->fifty, 4]]),
                $this->entered($this->safe, [[$this->twoHundred, 1]]),
                $this->entered($bag, [[$this->hundred, 1], [$this->fifty, 1]]),
            ],
            $this->amounts(),
            [$this->box->getId()->toRfc4122() => 15_000, $bar->getId()->toRfc4122() => 25_000],
            null,
            null,
            $this->actor,
            new \DateTimeImmutable(self::NOW),
        );

        // Box 20 000 − 15 000, bar 20 000 − 25 000, safe 20 000, bag 15 000.
        self::assertSame(5_000 - 5_000 + 20_000 + 15_000, $close->getCashIncome());
    }

    public function test_it_counts_every_active_denomination_of_a_counted_storage_and_only_the_deposited_ones(): void
    {
        $close = $this->record(box: [[$this->hundred, 6]], safe: [[$this->fifty, 2]]);

        $box = $close->ledgerEntryFor($this->box);
        self::assertEquals(LedgerEntry::count($close->position(), [self::id($this->fifty) => 0, self::id($this->hundred) => 6, self::id($this->twoHundred) => 0]), $box);
        self::assertEquals(LedgerEntry::delta($close->position(), [self::id($this->fifty) => 2]), $close->ledgerEntryFor($this->safe));
        self::assertNull($close->ledgerEntryFor($this->storage('Other', CashStorageMode::Counted)));
    }

    public function test_it_sits_in_the_ledger_at_the_close_to_the_second_in_utc(): void
    {
        $id = Uuid::v7();
        $close = $this->record(id: $id, now: new \DateTimeImmutable('2026-11-22 02:30:15.700', new \DateTimeZone('Europe/Warsaw')));

        self::assertSame('2026-11-22T01:30:15+00:00', $close->getClosedAt()->format(\DATE_ATOM));
        self::assertEquals(DayClose::positionAt($id, new \DateTimeImmutable('2026-11-22 01:30:15.999')), $close->position());
    }

    public function test_it_records_the_date_the_register_the_currency_the_actor_and_the_non_cash_amounts(): void
    {
        $close = $this->record(amounts: [[$this->blikId->toRfc4122(), 1_250], [strtoupper($this->cardId->toRfc4122()), 98_000]]);

        self::assertSame(['2026-11-21', $this->registerId->toRfc4122(), 'PLN', $this->actor->toRfc4122()], [$close->getBusinessDate()->toString(), $close->getRegisterId()->toRfc4122(), $close->getCurrency(), $close->getClosedBy()->toRfc4122()]);
        self::assertSame([$this->cardId->toRfc4122() => 98_000, $this->blikId->toRfc4122() => 1_250], $close->nonCashAmounts());
    }

    public function test_it_refuses_a_date_that_is_already_closed_and_says_who_closed_it(): void
    {
        $existing = new ClosedDay(Uuid::v7(), BusinessDate::fromString('2026-11-21'), Uuid::v7(), 'Eve', 'Employee', new \DateTimeImmutable('2026-11-22 00:10:00'));

        try {
            $this->record(sameDate: $existing);
            self::fail('DayAlreadyClosedException expected.');
        } catch (DayAlreadyClosedException $e) {
            self::assertSame('cash_flow.day_already_closed.by_member', $e->errorCode());
            self::assertSame(['date' => '2026-11-21', 'dayCloseId' => $existing->dayCloseId->toRfc4122(), 'closedBy' => $existing->closedBy->toRfc4122(), 'closedByFirstName' => 'Eve', 'closedByLastName' => 'Employee', 'closedAt' => '2026-11-22T00:10:00+00:00'], $e->errorParameters());
        }
    }

    public function test_it_refuses_a_date_before_or_on_the_latest_closed_one(): void
    {
        foreach (['2026-11-21', '2026-11-22'] as $lastClosed) {
            try {
                $this->record(lastClosedDate: BusinessDate::fromString($lastClosed));
                self::fail('DayCloseOutOfOrderException expected.');
            } catch (DayCloseOutOfOrderException $e) {
                self::assertSame(['date' => '2026-11-21', 'lastClosedDate' => $lastClosed], $e->errorParameters());
            }
        }

        self::assertSame('2026-11-21', $this->record(lastClosedDate: BusinessDate::fromString('2026-11-19'))->getBusinessDate()->toString());
    }

    public function test_it_refuses_a_close_when_nothing_can_be_counted(): void
    {
        $this->expectRefusal('cash_flow.invalid_day_close.nothing_to_close', fn () => $this->record(offer: $this->offer(denominations: [])));
        $this->expectRefusal('cash_flow.invalid_day_close.nothing_to_close', fn () => $this->record(offer: $this->offer(storages: [])));
    }

    public function test_it_refuses_a_register_that_is_not_active(): void
    {
        $this->expectRefusal('cash_flow.invalid_day_close.register_not_offered', fn () => $this->record(registerId: Uuid::v7()->toRfc4122()));
        $this->expectRefusal('cash_flow.invalid_day_close.register_not_offered', fn () => $this->record(registerId: 'not-a-uuid'));
    }

    public function test_it_refuses_a_close_that_leaves_out_a_storage_or_names_one_twice(): void
    {
        $this->expectRefusal('cash_flow.invalid_day_close.missing_storage', fn () => $this->record(counts: [$this->entered($this->box, [])]));
        $this->expectRefusal('cash_flow.invalid_day_close.duplicate_storage', fn () => $this->record(counts: [$this->entered($this->box, []), $this->entered($this->safe, []), $this->entered($this->box, [])]));
    }

    public function test_it_refuses_a_deactivated_storage(): void
    {
        $old = $this->storage('Old box', CashStorageMode::Counted);
        $old->deactivate($this->actor, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $this->expectException(StorageDeactivatedException::class);
        $this->record(counts: [$this->entered($this->box, []), $this->entered($this->safe, []), $this->entered($old, [])]);
    }

    public function test_it_refuses_repeated_denominations_and_pieces_out_of_range(): void
    {
        $this->expectRefusal('cash_flow.invalid_day_close.duplicate_denomination', fn () => $this->record(box: [[$this->hundred, 1], [$this->hundred, 2]]));
        $this->expectRefusal('cash_flow.invalid_day_close.pieces', fn () => $this->record(safe: [[$this->hundred, -1]]));
        $this->expectRefusal('cash_flow.invalid_day_close.pieces', fn () => $this->record(box: [[$this->hundred, 1_000_001]]));
    }

    public function test_it_refuses_a_denomination_in_another_currency_or_deactivated(): void
    {
        $euro = Denomination::create(Uuid::v7(), 'EUR', 1_000, DenominationKind::Note, null, $this->actor, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
        $this->expectRefusal('cash_flow.denomination_not_offered.other_currency', fn () => $this->record(box: [[$euro, 1]]));

        $this->fifty->deactivate($this->actor, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
        $this->expectRefusal('cash_flow.denomination_not_offered.deactivated', fn () => $this->record(safe: [[$this->fifty, 1]]));
    }

    public function test_it_refuses_payments_that_do_not_match_the_active_non_cash_methods(): void
    {
        $this->expectRefusal('cash_flow.invalid_day_close.missing_payment_method', fn () => $this->record(amounts: [[$this->cardId->toRfc4122(), 0]]));
        $this->expectRefusal('cash_flow.invalid_day_close.payment_method_not_offered', fn () => $this->record(amounts: [...$this->amounts(), [Uuid::v7()->toRfc4122(), 0]]));
        $this->expectRefusal('cash_flow.invalid_day_close.duplicate_payment_method', fn () => $this->record(amounts: [...$this->amounts(), [$this->cardId->toRfc4122(), 1]]));
        $this->expectRefusal('cash_flow.invalid_day_close.amount', fn () => $this->record(amounts: [[$this->cardId->toRfc4122(), -1], [$this->blikId->toRfc4122(), 0]]));
        $this->expectRefusal('cash_flow.invalid_day_close.amount', fn () => $this->record(amounts: [[$this->cardId->toRfc4122(), RevenueEntry::MAX_AMOUNT + 1], [$this->blikId->toRfc4122(), 0]]));
    }

    public function test_it_takes_no_amounts_when_no_non_cash_method_is_active(): void
    {
        $close = $this->record(offer: $this->offer(nonCash: []), amounts: []);

        self::assertSame([], $close->nonCashAmounts());
    }

    /**
     * @param list<array{Denomination, int}>|null $box
     * @param list<array{Denomination, int}>|null $safe
     * @param list<StorageCount>|null             $counts
     * @param list<array{string, int}>|null       $amounts
     * @param array<string, int>|null             $expectedOpenings
     */
    private function record(
        ?array $box = null,
        ?array $safe = null,
        ?array $counts = null,
        ?array $amounts = null,
        ?array $expectedOpenings = null,
        ?DayCloseOffer $offer = null,
        ?string $registerId = null,
        ?ClosedDay $sameDate = null,
        ?BusinessDate $lastClosedDate = null,
        ?Uuid $id = null,
        ?\DateTimeImmutable $now = null,
    ): DayClose {
        return DayClose::record(
            $id ?? Uuid::v7(),
            BusinessDate::fromString('2026-11-21'),
            $offer ?? $this->offer(),
            $registerId ?? $this->registerId->toRfc4122(),
            $counts ?? [$this->entered($this->box, $box ?? []), $this->entered($this->safe, $safe ?? [])],
            $amounts ?? $this->amounts(),
            $expectedOpenings ?? [$this->box->getId()->toRfc4122() => 0],
            $sameDate,
            $lastClosedDate,
            $this->actor,
            $now ?? new \DateTimeImmutable(self::NOW),
        );
    }

    /**
     * @param list<CashStorage>|null     $storages
     * @param list<Denomination>|null    $denominations
     * @param array<string, string>|null $nonCash
     */
    private function offer(?array $storages = null, ?array $denominations = null, ?array $nonCash = null): DayCloseOffer
    {
        return new DayCloseOffer(
            'PLN',
            $storages ?? [$this->box, $this->safe],
            $denominations ?? [$this->fifty, $this->hundred, $this->twoHundred],
            [$this->registerId->toRfc4122() => 'Bar'],
            $nonCash ?? [$this->cardId->toRfc4122() => 'Card', $this->blikId->toRfc4122() => 'BLIK'],
        );
    }

    /** @return list<array{string, int}> */
    private function amounts(): array
    {
        return [[$this->cardId->toRfc4122(), 0], [$this->blikId->toRfc4122(), 0]];
    }

    /** @param list<array{Denomination, int}> $pieces */
    private function entered(CashStorage $storage, array $pieces): StorageCount
    {
        return new StorageCount($storage, array_map(static fn (array $line): MovementPieces => new MovementPieces($line[0], $line[1]), $pieces));
    }

    private function storage(string $name, CashStorageMode $mode): CashStorage
    {
        return CashStorage::create(Uuid::v7(), $name, $mode, null, $this->actor, MembershipRole::Owner, new \DateTimeImmutable('2026-01-01'));
    }

    private function note(int $value): Denomination
    {
        return Denomination::create(Uuid::v7(), 'PLN', $value, DenominationKind::Note, null, $this->actor, MembershipRole::Owner, new \DateTimeImmutable('2026-01-01'));
    }

    private static function id(Denomination $denomination): string
    {
        return $denomination->getId()->toRfc4122();
    }

    /** @return list<array{string, string, int, ?int, int}> name, mode, total, expected opening and cash income per storage */
    private static function storages(DayClose $close): array
    {
        return array_map(
            static fn (DayCloseStorage $storage): array => [$storage->getStorage()->getName(), $storage->getMode()->value, $storage->getTotal(), $storage->getExpectedOpening(), $storage->cashIncome()],
            $close->getStorages(),
        );
    }

    /** @param \Closure(): mixed $call */
    private function expectRefusal(string $code, \Closure $call): void
    {
        try {
            $call();
            self::fail(\sprintf('%s expected.', $code));
        } catch (InvalidDayCloseException|DenominationNotOfferedException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }
}
