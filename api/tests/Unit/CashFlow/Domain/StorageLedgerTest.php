<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\StorageBalanceNegativeException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Model\LedgerEntry;
use App\CashFlow\Domain\Model\LedgerPosition;
use App\CashFlow\Domain\Model\StorageLedger;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StorageLedger::class)]
#[CoversClass(LedgerEntry::class)]
#[CoversClass(LedgerPosition::class)]
final class StorageLedgerTest extends TestCase
{
    private const string NOTE = 'aaaaaaaa-0000-7000-8000-000000010000';
    private const string COIN = 'aaaaaaaa-0000-7000-8000-000000000500';

    public function test_it_starts_a_balance_from_the_last_count_and_adds_the_signed_entries_after_it(): void
    {
        $balance = StorageLedger::balance(['old' => 99], [
            LedgerEntry::delta(self::at('08:00'), [self::NOTE => 5]),
            LedgerEntry::count(self::at('09:00'), [self::NOTE => 3, self::COIN => 0]),
            LedgerEntry::delta(self::at('10:00'), [self::NOTE => -1, self::COIN => 4]),
        ]);

        // The count replaced everything before it, the old pieces included; zeros are left out.
        self::assertSame([self::NOTE => 2, self::COIN => 4], $balance);
    }

    public function test_it_lets_a_transfer_take_out_what_a_deposit_storage_holds_and_refuses_one_piece_more(): void
    {
        $safe = self::storage(CashStorageMode::Deposit);
        $opening = [self::NOTE => 2];

        StorageLedger::assertNeverNegative($safe, $opening, [LedgerEntry::delta(self::at('10:00'), [self::NOTE => -2])], self::denominations());

        try {
            StorageLedger::assertNeverNegative($safe, $opening, [LedgerEntry::delta(self::at('10:00'), [self::NOTE => -3])], self::denominations());
            self::fail('The balance should go below zero.');
        } catch (StorageBalanceNegativeException $e) {
            self::assertSame('cash_flow.storage_balance_negative', $e->errorCode());
            self::assertSame([
                'storageId' => $safe->getId()->toRfc4122(),
                'name' => 'Sejf',
                'denominationId' => self::NOTE,
                'currency' => 'PLN',
                'value' => 10_000,
                'pieces' => -1,
                'at' => '2026-11-22T10:00:00+00:00',
            ], $e->errorParameters());
        }
    }

    public function test_it_refuses_a_backdated_entry_that_leaves_a_later_withdrawal_without_cover(): void
    {
        // The safe got 5 notes at 08:00 and gave 4 to the bank at 12:00. A withdrawal of 2 backdated
        // to 10:00 is fine at 10:00 (3 left), but leaves -1 after the bank run.
        $entries = [
            LedgerEntry::delta(self::at('10:00'), [self::NOTE => -2]),
            LedgerEntry::delta(self::at('12:00'), [self::NOTE => -4]),
        ];

        try {
            StorageLedger::assertNeverNegative(self::storage(CashStorageMode::Deposit), [self::NOTE => 5], $entries, self::denominations());
            self::fail('The bank run should go below zero.');
        } catch (StorageBalanceNegativeException $e) {
            self::assertSame(['pieces' => -1, 'at' => '2026-11-22T12:00:00+00:00'], array_intersect_key($e->errorParameters(), ['pieces' => true, 'at' => true]));
        }
    }

    public function test_it_lets_a_later_count_cover_what_came_before_it(): void
    {
        $entries = [
            LedgerEntry::count(self::at('11:00'), [self::NOTE => 0]),
            LedgerEntry::delta(self::at('12:00'), [self::COIN => 3]),
        ];

        StorageLedger::assertNeverNegative(self::storage(CashStorageMode::Deposit), [self::NOTE => 1], $entries, self::denominations());

        self::assertSame([self::COIN => 3], StorageLedger::balance([self::NOTE => 1], $entries));
    }

    public function test_it_does_not_check_a_counted_storage_between_closes(): void
    {
        $this->expectNotToPerformAssertions();

        // The day's takings are in the cash box but not counted: a 200 note goes out before the close.
        StorageLedger::assertNeverNegative(self::storage(CashStorageMode::Counted), [], [LedgerEntry::delta(self::at('20:00'), [self::NOTE => -1])], []);
    }

    public function test_it_orders_the_ledger_by_moment_then_by_id(): void
    {
        $first = new LedgerPosition(new \DateTimeImmutable('2026-11-22 10:00:00'), Uuid::fromString('01900000-0000-7000-8000-000000000002'));
        $sameMomentLaterId = new LedgerPosition(new \DateTimeImmutable('2026-11-22 10:00:00'), Uuid::fromString('01900000-0000-7000-8000-000000000003'));
        $earlierMomentLaterId = new LedgerPosition(new \DateTimeImmutable('2026-11-22 09:59:59'), Uuid::fromString('01900000-0000-7000-8000-000000000009'));

        self::assertTrue($first->isBefore($sameMomentLaterId));
        self::assertFalse($sameMomentLaterId->isBefore($first));
        self::assertTrue($earlierMomentLaterId->isBefore($first));
        self::assertFalse($first->isBefore($first));
    }

    private static function at(string $time): LedgerPosition
    {
        return new LedgerPosition(new \DateTimeImmutable('2026-11-22 '.$time.':00', new \DateTimeZone('UTC')), Uuid::v7());
    }

    private static function storage(CashStorageMode $mode): CashStorage
    {
        return CashStorage::create(Uuid::v7(), CashStorageMode::Deposit === $mode ? 'Sejf' : 'Kasetka', $mode, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
    }

    /** @return array<string, Denomination> */
    private static function denominations(): array
    {
        $now = new \DateTimeImmutable('2026-11-01');

        return [
            self::NOTE => Denomination::create(Uuid::fromString(self::NOTE), 'PLN', 10_000, DenominationKind::Note, null, Uuid::v7(), MembershipRole::Owner, $now),
            self::COIN => Denomination::create(Uuid::fromString(self::COIN), 'PLN', 500, DenominationKind::Coin, null, Uuid::v7(), MembershipRole::Owner, $now),
        ];
    }
}
