<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\AuditCountNeedsDepositStorageException;
use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\CashFlowException;
use App\CashFlow\Domain\Exception\DenominationNotOfferedException;
use App\CashFlow\Domain\Exception\InvalidMovementException;
use App\CashFlow\Domain\Exception\MovementInTheFutureException;
use App\CashFlow\Domain\Exception\StorageDeactivatedException;
use App\CashFlow\Domain\Model\CashMovement;
use App\CashFlow\Domain\Model\CashMovementKind;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\CashFlow\Domain\Model\MovementPieces;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CashMovement::class)]
final class CashMovementTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    private CashStorage $box;
    private CashStorage $safe;
    private Denomination $fifty;
    private Denomination $note;
    private Uuid $manager;

    protected function setUp(): void
    {
        $this->manager = Uuid::v7();
        $this->box = self::storage('Kasetka', CashStorageMode::Counted);
        $this->safe = self::storage('Sejf', CashStorageMode::Deposit);
        $this->fifty = self::denomination(5_000, DenominationKind::Note);
        $this->note = self::denomination(10_000, DenominationKind::Note);
    }

    public function test_it_records_an_adjustment_now_with_signed_pieces_and_a_trimmed_note(): void
    {
        $id = Uuid::v7();

        $movement = CashMovement::adjust($id, $this->safe, [new MovementPieces($this->note, 3), new MovementPieces($this->fifty, -1)], 'pln', '  Change from the bank ', null, $this->manager, MembershipRole::Manager, self::now());

        self::assertTrue($movement->getId()->equals($id));
        self::assertSame(CashMovementKind::Adjustment, $movement->getKind());
        self::assertEquals(self::now(), $movement->getOccurredAt());
        self::assertSame('Change from the bank', $movement->getNote());
        self::assertTrue($movement->getCreatedBy()->equals($this->manager));
        self::assertEquals(self::now(), $movement->getCreatedAt());
        self::assertSame([$this->note->getId()->toRfc4122() => 3, $this->fifty->getId()->toRfc4122() => -1], $movement->ledgerEntryFor($this->safe)?->pieces);
        self::assertFalse($movement->ledgerEntryFor($this->safe)->isCount);
        self::assertNull($movement->ledgerEntryFor($this->box));
    }

    public function test_it_keeps_an_earlier_moment_in_utc_to_the_second_and_refuses_a_later_one(): void
    {
        $movement = CashMovement::adjust(Uuid::v7(), $this->safe, [new MovementPieces($this->note, 1)], 'PLN', null, new \DateTimeImmutable('2026-11-21T23:30:15.750+01:00'), $this->manager, MembershipRole::Owner, self::now());

        self::assertSame('2026-11-21T22:30:15+00:00', $movement->getOccurredAt()->format(\DATE_ATOM));
        self::assertSame('000000', $movement->getOccurredAt()->format('u'));

        try {
            CashMovement::adjust(Uuid::v7(), $this->safe, [new MovementPieces($this->note, 1)], 'PLN', null, new \DateTimeImmutable('2026-11-22T10:00:01+00:00'), $this->manager, MembershipRole::Owner, self::now());
            self::fail('A later moment should be refused.');
        } catch (MovementInTheFutureException $e) {
            self::assertSame('cash_flow.movement_in_the_future', $e->errorCode());
            self::assertSame(['occurredAt' => '2026-11-22T10:00:01+00:00', 'now' => '2026-11-22T10:00:00+00:00'], $e->errorParameters());
        }
    }

    public function test_it_moves_the_same_pieces_out_of_one_storage_and_into_another(): void
    {
        $movement = CashMovement::transfer(Uuid::v7(), $this->box, $this->safe, [new MovementPieces($this->note, 2)], 'PLN', null, null, $this->manager, MembershipRole::Manager, self::now());

        self::assertSame(CashMovementKind::Transfer, $movement->getKind());
        self::assertSame([$this->box, $this->safe], $movement->storages());
        self::assertSame([$this->note->getId()->toRfc4122() => -2], $movement->ledgerEntryFor($this->box)?->pieces);
        self::assertSame([$this->note->getId()->toRfc4122() => 2], $movement->ledgerEntryFor($this->safe)?->pieces);
    }

    public function test_it_counts_every_active_denomination_of_a_deposit_storage_with_the_ones_left_out_as_zero(): void
    {
        $movement = CashMovement::count(Uuid::v7(), $this->safe, [new MovementPieces($this->note, 4)], [$this->fifty, $this->note], 'PLN', null, null, $this->manager, MembershipRole::Owner, self::now());

        $entry = $movement->ledgerEntryFor($this->safe);
        self::assertTrue($entry?->isCount);
        self::assertSame([$this->fifty->getId()->toRfc4122() => 0, $this->note->getId()->toRfc4122() => 4], $entry->pieces);

        $empty = CashMovement::count(Uuid::v7(), $this->safe, [], [$this->note], 'PLN', null, null, $this->manager, MembershipRole::Owner, self::now());
        self::assertSame([$this->note->getId()->toRfc4122() => 0], $empty->ledgerEntryFor($this->safe)?->pieces);
    }

    public function test_it_audits_only_deposit_storages(): void
    {
        try {
            CashMovement::count(Uuid::v7(), $this->box, [], [$this->note], 'PLN', null, null, $this->manager, MembershipRole::Owner, self::now());
            self::fail('A counted storage should be refused.');
        } catch (AuditCountNeedsDepositStorageException $e) {
            self::assertSame('cash_flow.audit_count_needs_deposit_storage', $e->errorCode());
            self::assertSame(['storageId' => $this->box->getId()->toRfc4122(), 'name' => 'Kasetka'], $e->errorParameters());
        }
    }

    /** @return iterable<string, array{\Closure(self): mixed, class-string<CashFlowException>, string}> */
    public static function refusedMovements(): iterable
    {
        yield 'an employee' => [static fn (self $t): mixed => $t->adjust([new MovementPieces($t->note, 1)], role: MembershipRole::Employee), CashFlowAccessForbiddenException::class, 'cash_flow.access_forbidden.to_manage'];
        yield 'no line' => [static fn (self $t): mixed => $t->adjust([]), InvalidMovementException::class, 'cash_flow.invalid_movement.without_lines'];
        yield 'zero pieces' => [static fn (self $t): mixed => $t->adjust([new MovementPieces($t->note, 0)]), InvalidMovementException::class, 'cash_flow.invalid_movement.pieces'];
        yield 'too many pieces' => [static fn (self $t): mixed => $t->adjust([new MovementPieces($t->note, -CashMovement::MAX_PIECES - 1)]), InvalidMovementException::class, 'cash_flow.invalid_movement.pieces'];
        yield 'a repeated denomination' => [static fn (self $t): mixed => $t->adjust([new MovementPieces($t->note, 1), new MovementPieces($t->note, -1)]), InvalidMovementException::class, 'cash_flow.invalid_movement.duplicate_denomination'];
        yield 'a too long note' => [static fn (self $t): mixed => $t->adjust([new MovementPieces($t->note, 1)], note: str_repeat('a', CashMovement::NOTE_MAX_LENGTH + 1)), InvalidMovementException::class, 'cash_flow.invalid_movement.note'];
        yield 'another currency' => [static fn (self $t): mixed => $t->adjust([new MovementPieces(self::denomination(100, DenominationKind::Coin, 'EUR'), 1)]), DenominationNotOfferedException::class, 'cash_flow.denomination_not_offered.other_currency'];
        yield 'adding a deactivated denomination' => [static fn (self $t): mixed => $t->adjust([new MovementPieces(self::deactivated(self::denomination(20_000, DenominationKind::Note)), 1)]), DenominationNotOfferedException::class, 'cash_flow.denomination_not_offered.deactivated'];
        yield 'adding to a deactivated storage' => [static fn (self $t): mixed => $t->adjust([new MovementPieces($t->note, 1)], storage: self::deactivatedStorage()), StorageDeactivatedException::class, 'cash_flow.storage_deactivated'];
        yield 'a transfer into the same storage' => [static fn (self $t): mixed => CashMovement::transfer(Uuid::v7(), $t->safe, $t->safe, [new MovementPieces($t->note, 1)], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), InvalidMovementException::class, 'cash_flow.invalid_movement.same_storage'];
        yield 'a transfer of negative pieces' => [static fn (self $t): mixed => CashMovement::transfer(Uuid::v7(), $t->box, $t->safe, [new MovementPieces($t->note, -1)], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), InvalidMovementException::class, 'cash_flow.invalid_movement.pieces'];
        yield 'a transfer of a deactivated denomination' => [static fn (self $t): mixed => CashMovement::transfer(Uuid::v7(), $t->box, $t->safe, [new MovementPieces(self::deactivated(self::denomination(20_000, DenominationKind::Note)), 1)], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), DenominationNotOfferedException::class, 'cash_flow.denomination_not_offered.deactivated'];
        yield 'a transfer into a deactivated storage' => [static fn (self $t): mixed => CashMovement::transfer(Uuid::v7(), $t->box, self::deactivatedStorage(), [new MovementPieces($t->note, 1)], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), StorageDeactivatedException::class, 'cash_flow.storage_deactivated'];
        yield 'a count of negative pieces' => [static fn (self $t): mixed => CashMovement::count(Uuid::v7(), $t->safe, [new MovementPieces($t->note, -1)], [$t->note], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), InvalidMovementException::class, 'cash_flow.invalid_movement.pieces'];
        yield 'a count with nothing offered' => [static fn (self $t): mixed => CashMovement::count(Uuid::v7(), $t->safe, [], [], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), InvalidMovementException::class, 'cash_flow.invalid_movement.without_lines'];
        yield 'a count of a deactivated storage' => [static fn (self $t): mixed => CashMovement::count(Uuid::v7(), self::deactivatedStorage(), [], [$t->note], 'PLN', null, null, $t->manager, MembershipRole::Owner, self::now()), StorageDeactivatedException::class, 'cash_flow.storage_deactivated'];
    }

    /**
     * @param \Closure(self): mixed            $record
     * @param class-string<CashFlowException> $exception
     */
    #[DataProvider('refusedMovements')]
    public function test_it_refuses_a_movement_that_breaks_a_rule(\Closure $record, string $exception, string $code): void
    {
        try {
            $record($this);
            self::fail('The movement should be refused.');
        } catch (CashFlowException $e) {
            self::assertInstanceOf($exception, $e);
            self::assertSame($code, $e->errorCode());
        }
    }

    public function test_it_lets_an_adjustment_empty_a_deactivated_storage_of_a_deactivated_denomination(): void
    {
        $old = self::deactivated(self::denomination(20_000, DenominationKind::Note));

        $movement = $this->adjust([new MovementPieces($old, -2)], storage: $storage = self::deactivatedStorage());

        self::assertSame([$old->getId()->toRfc4122() => -2], $movement->ledgerEntryFor($storage)?->pieces);
    }

    public function test_it_lets_only_owners_and_managers_delete_a_movement(): void
    {
        $movement = $this->adjust([new MovementPieces($this->note, 1)]);
        $movement->assertDeletable(Uuid::v7(), MembershipRole::Manager);

        $this->expectException(CashFlowAccessForbiddenException::class);
        $movement->assertDeletable(Uuid::v7(), MembershipRole::Employee);
    }

    /** @param list<MovementPieces> $pieces */
    private function adjust(array $pieces, ?CashStorage $storage = null, ?string $note = null, MembershipRole $role = MembershipRole::Manager): CashMovement
    {
        return CashMovement::adjust(Uuid::v7(), $storage ?? $this->safe, $pieces, 'PLN', $note, null, $this->manager, $role, self::now());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private static function storage(string $name, CashStorageMode $mode): CashStorage
    {
        return CashStorage::create(Uuid::v7(), $name, $mode, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
    }

    private static function deactivatedStorage(): CashStorage
    {
        $storage = self::storage('Stary sejf', CashStorageMode::Deposit);
        $storage->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-10'));

        return $storage;
    }

    private static function denomination(int $value, DenominationKind $kind, string $currency = 'PLN'): Denomination
    {
        return Denomination::create(Uuid::v7(), $currency, $value, $kind, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));
    }

    private static function deactivated(Denomination $denomination): Denomination
    {
        $denomination->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-10'));

        return $denomination;
    }
}
