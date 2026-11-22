<?php

declare(strict_types=1);

namespace App\Tests\Unit\CashFlow\Domain;

use App\CashFlow\Domain\Exception\CashFlowAccessForbiddenException;
use App\CashFlow\Domain\Exception\DenominationInUseException;
use App\CashFlow\Domain\Exception\DenominationValueTakenException;
use App\CashFlow\Domain\Exception\InvalidDenominationValueException;
use App\CashFlow\Domain\Model\Denomination;
use App\CashFlow\Domain\Model\DenominationKind;
use App\Organization\Domain\Model\MembershipRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Denomination::class)]
final class DenominationTest extends TestCase
{
    private const string NOW = '2026-11-22 10:00:00';

    public function test_it_creates_an_active_denomination_in_the_currency_given(): void
    {
        $id = Uuid::v7();

        $denomination = Denomination::create($id, 'pln', 500, DenominationKind::Coin, null, Uuid::v7(), MembershipRole::Manager, new \DateTimeImmutable(self::NOW));

        self::assertTrue($denomination->getId()->equals($id));
        self::assertSame('PLN', $denomination->getCurrency());
        self::assertSame(500, $denomination->getValue());
        self::assertSame(DenominationKind::Coin, $denomination->getKind());
        self::assertTrue($denomination->isActive());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $denomination->getCreatedAt());
        self::assertNull($denomination->getDeactivatedAt());
    }

    public function test_it_refuses_employees_every_change(): void
    {
        $denomination = self::denomination(500);
        $employee = Uuid::v7();
        $attempts = [
            'create' => static fn () => Denomination::create(Uuid::v7(), 'PLN', 100, DenominationKind::Coin, null, $employee, MembershipRole::Employee, new \DateTimeImmutable(self::NOW)),
            'deactivate' => static fn () => $denomination->deactivate($employee, MembershipRole::Employee, new \DateTimeImmutable(self::NOW)),
            'reactivate' => static fn () => $denomination->reactivate($employee, MembershipRole::Employee),
            'delete' => static fn () => $denomination->assertDeletable(false, $employee, MembershipRole::Employee),
        ];

        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                self::fail($case.' should be refused.');
            } catch (CashFlowAccessForbiddenException $e) {
                self::assertSame('cash_flow.access_forbidden', $e->errorCode(), $case);
                self::assertSame(['userId' => $employee->toRfc4122()], $e->errorParameters(), $case);
            }
        }
        self::assertTrue($denomination->isActive());
    }

    /** @return iterable<string, array{int}> */
    public static function invalidValues(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-100];
        yield 'too large' => [Denomination::MAX_VALUE + 1];
    }

    #[DataProvider('invalidValues')]
    public function test_it_refuses_a_value_out_of_range(int $value): void
    {
        $this->expectException(InvalidDenominationValueException::class);

        self::denomination($value);
    }

    public function test_it_accepts_the_largest_value(): void
    {
        self::assertSame(Denomination::MAX_VALUE, self::denomination(Denomination::MAX_VALUE)->getValue());
    }

    public function test_it_refuses_a_value_the_organization_already_has(): void
    {
        $existing = self::denomination(500);

        try {
            Denomination::create(Uuid::v7(), 'PLN', 500, DenominationKind::Coin, $existing, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
            self::fail('A taken value should be refused.');
        } catch (DenominationValueTakenException $e) {
            self::assertSame('cash_flow.denomination_value_taken', $e->errorCode());
            self::assertSame(['currency' => 'PLN', 'value' => 500], $e->errorParameters());
        }
    }

    public function test_it_deactivates_once_and_reactivates(): void
    {
        $denomination = self::denomination(500);
        $actor = Uuid::v7();

        $denomination->deactivate($actor, MembershipRole::Owner, new \DateTimeImmutable('2026-11-22 11:00:00'));
        $denomination->deactivate($actor, MembershipRole::Owner, new \DateTimeImmutable('2026-11-22 12:00:00'));

        self::assertFalse($denomination->isActive());
        self::assertEquals(new \DateTimeImmutable('2026-11-22 11:00:00'), $denomination->getDeactivatedAt());

        $denomination->reactivate($actor, MembershipRole::Manager);
        $denomination->reactivate($actor, MembershipRole::Manager);

        self::assertTrue($denomination->isActive());
        self::assertNull($denomination->getDeactivatedAt());
    }

    public function test_it_is_deletable_only_while_unused(): void
    {
        $denomination = self::denomination(500);
        $denomination->assertDeletable(false, Uuid::v7(), MembershipRole::Owner);

        try {
            $denomination->assertDeletable(true, Uuid::v7(), MembershipRole::Owner);
            self::fail('A used denomination should not be deletable.');
        } catch (DenominationInUseException $e) {
            self::assertSame('cash_flow.denomination_in_use', $e->errorCode());
            self::assertSame(['denominationId' => $denomination->getId()->toRfc4122(), 'currency' => 'PLN', 'value' => 500], $e->errorParameters());
        }
    }

    public function test_it_orders_active_denominations_first_then_by_value(): void
    {
        $fiveZloty = self::denomination(500);
        $oneGrosz = self::denomination(1);
        $tenZloty = self::denomination(1_000);
        $twoGrosze = self::denomination(2);
        $twoGrosze->deactivate(Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
        $list = [$tenZloty, $twoGrosze, $fiveZloty, $oneGrosz];

        usort($list, Denomination::compare(...));

        self::assertSame([1, 500, 1_000, 2], array_map(static fn (Denomination $d): int => $d->getValue(), $list));
    }

    private static function denomination(int $value): Denomination
    {
        return Denomination::create(Uuid::v7(), 'PLN', $value, $value >= 1_000 ? DenominationKind::Note : DenominationKind::Coin, null, Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }
}
