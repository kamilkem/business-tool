<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\InvalidStorageNameException;
use App\CashFlow\Domain\Exception\StorageInUseException;
use App\CashFlow\Domain\Exception\StorageNameTakenException;
use App\CashFlow\Domain\Model\CashStorage;
use App\CashFlow\Domain\Model\CashStorageMode;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(CashStorage::class)]
final class CashStorageTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    public function test_it_creates_an_active_storage_with_a_trimmed_name(): void
    {
        $id = Uuid::v7();

        $storage = CashStorage::create($id, '  Kasetka ', CashStorageMode::Counted, null, Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable(self::NOW));

        self::assertTrue($storage->getId()->equals($id));
        self::assertSame('Kasetka', $storage->getName());
        self::assertSame(CashStorageMode::Counted, $storage->getMode());
        self::assertTrue($storage->isActive());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $storage->getCreatedAt());
        self::assertNull($storage->getDeactivatedAt());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('ś', CashStorage::NAME_MAX_LENGTH + 1)];
    }

    #[DataProvider('invalidNames')]
    public function test_it_refuses_a_blank_or_too_long_name(string $name): void
    {
        try {
            CashStorage::create(Uuid::v7(), $name, CashStorageMode::Deposit, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
            self::fail('The name should be refused.');
        } catch (InvalidStorageNameException $e) {
            self::assertSame('cash_flow.invalid_storage_name', $e->errorCode());
            self::assertSame(['name' => trim($name), 'maxLength' => 64], $e->errorParameters());
        }

        $storage = self::storage('Sejf');
        $this->expectException(InvalidStorageNameException::class);
        $storage->rename($name, null, Uuid::v7(), MembershipRole::Owner);
    }

    public function test_it_accepts_a_name_of_64_characters(): void
    {
        $name = str_repeat('ś', CashStorage::NAME_MAX_LENGTH);

        self::assertSame($name, self::storage($name)->getName());
    }

    public function test_it_refuses_a_name_another_storage_has(): void
    {
        $other = self::storage('Sejf');

        try {
            CashStorage::create(Uuid::v7(), ' Sejf ', CashStorageMode::Deposit, $other, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
            self::fail('The name should be taken.');
        } catch (StorageNameTakenException $e) {
            self::assertSame('cash_flow.storage_name_taken', $e->errorCode());
            self::assertSame(['name' => 'Sejf'], $e->errorParameters());
        }

        $storage = self::storage('Kasetka');
        $this->expectException(StorageNameTakenException::class);
        $storage->rename('Sejf', $other, Uuid::v7(), MembershipRole::Owner);
    }

    public function test_it_renames_including_to_its_own_name(): void
    {
        $storage = self::storage('Kasetka');

        $storage->rename(' Kasetka ', $storage, Uuid::v7(), MembershipRole::Manager);
        self::assertSame('Kasetka', $storage->getName());

        $storage->rename('Kasetka bar', null, Uuid::v7(), MembershipRole::Manager);
        self::assertSame('Kasetka bar', $storage->getName());
    }

    public function test_it_changes_the_mode(): void
    {
        $storage = self::storage('Kasetka');

        $storage->changeMode(CashStorageMode::Deposit, Uuid::v7(), MembershipRole::Owner);
        self::assertSame(CashStorageMode::Deposit, $storage->getMode());

        $storage->changeMode(CashStorageMode::Deposit, Uuid::v7(), MembershipRole::Owner);
        self::assertSame(CashStorageMode::Deposit, $storage->getMode());
    }

    public function test_it_keeps_the_first_deactivation_moment_and_reactivates(): void
    {
        $storage = self::storage('Sejf');

        $storage->deactivate(Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable(self::NOW));
        $storage->deactivate(Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable('2026-11-23 10:00:00'));
        self::assertFalse($storage->isActive());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $storage->getDeactivatedAt());

        $storage->reactivate(Uuid::v7(), MembershipRole::Manager);
        $storage->reactivate(Uuid::v7(), MembershipRole::Manager);
        self::assertTrue($storage->isActive());
        self::assertNull($storage->getDeactivatedAt());
    }

    public function test_it_is_deletable_only_while_unused(): void
    {
        $storage = self::storage('Sejf');
        $storage->assertDeletable(false, Uuid::v7(), MembershipRole::Owner);

        try {
            $storage->assertDeletable(true, Uuid::v7(), MembershipRole::Owner);
            self::fail('A used storage should not be deletable.');
        } catch (StorageInUseException $e) {
            self::assertSame('cash_flow.storage_in_use', $e->errorCode());
            self::assertSame(['storageId' => $storage->getId()->toRfc4122(), 'name' => 'Sejf'], $e->errorParameters());
        }
    }

    public function test_it_refuses_employees_every_change(): void
    {
        $storage = self::storage('Sejf');
        $employee = Uuid::v7();
        $attempts = [
            'create' => static fn () => CashStorage::create(Uuid::v7(), 'Kasetka', CashStorageMode::Counted, null, $employee, MembershipRole::Employee, new \DateTimeImmutable(self::NOW)),
            'rename' => static fn () => $storage->rename('Kasetka', null, $employee, MembershipRole::Employee),
            'change mode' => static fn () => $storage->changeMode(CashStorageMode::Counted, $employee, MembershipRole::Employee),
            'deactivate' => static fn () => $storage->deactivate($employee, MembershipRole::Employee, new \DateTimeImmutable(self::NOW)),
            'reactivate' => static fn () => $storage->reactivate($employee, MembershipRole::Employee),
            'delete' => static fn () => $storage->assertDeletable(false, $employee, MembershipRole::Employee),
        ];

        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                self::fail($case.' should be refused.');
            } catch (CashFlowAccessForbiddenException $e) {
                self::assertSame('cash_flow.access_forbidden.to_manage', $e->errorCode(), $case);
                self::assertSame(['userId' => $employee->toRfc4122()], $e->errorParameters(), $case);
            }
        }
        self::assertSame(['Sejf', CashStorageMode::Deposit, true], [$storage->getName(), $storage->getMode(), $storage->isActive()]);
    }

    public function test_it_orders_active_storages_first_then_by_name(): void
    {
        $safe = self::storage('Sejf');
        $box = self::storage('Kasetka');
        $old = self::storage('Archive');
        $old->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $list = [$old, $safe, $box];
        usort($list, CashStorage::compare(...));

        self::assertSame(['Kasetka', 'Sejf', 'Archive'], array_map(static fn (CashStorage $s): string => $s->getName(), $list));
    }

    private static function storage(string $name): CashStorage
    {
        return CashStorage::create(Uuid::v7(), $name, CashStorageMode::Deposit, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }
}
