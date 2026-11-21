<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Domain;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentMethodNameException;
use App\Income\Domain\Exception\PaymentMethodInUseException;
use App\Income\Domain\Exception\PaymentMethodNameTakenException;
use App\Income\Domain\Exception\PaymentMethodOrderMismatchException;
use App\Income\Domain\Model\DefaultPaymentMethods;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\PaymentMethodOrder;
use App\Organization\Domain\Model\MembershipRole;
use App\Tests\Fixtures\PaymentMethodMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PaymentMethod::class)]
#[CoversClass(PaymentMethodOrder::class)]
#[CoversClass(DefaultPaymentMethods::class)]
final class PaymentMethodTest extends TestCase
{
    private const string NOW = '2026-11-12 10:00:00';

    private Uuid $actorId;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
    }

    public function test_it_creates_an_active_method_with_a_trimmed_name_at_its_position(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $method = PaymentMethod::create($id, '  BLIK ', null, 4, $this->actorId, MembershipRole::Manager, $now);

        self::assertSame($id, $method->getId());
        self::assertSame('BLIK', $method->getName());
        self::assertSame(4, $method->getPosition());
        self::assertSame($now, $method->getCreatedAt());
        self::assertTrue($method->isActive());
        self::assertNull($method->getDeactivatedAt());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => [str_repeat('x', PaymentMethod::NAME_MAX_LENGTH + 1)];
    }

    #[DataProvider('invalidNames')]
    public function test_it_rejects_an_invalid_name(string $name): void
    {
        $this->expectException(InvalidPaymentMethodNameException::class);

        PaymentMethod::create(Uuid::v7(), $name, null, 0, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_accepts_a_name_of_the_largest_length_in_characters(): void
    {
        $name = str_repeat('ż', PaymentMethod::NAME_MAX_LENGTH);

        self::assertSame($name, PaymentMethod::create(Uuid::v7(), $name, null, 0, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW))->getName());
    }

    public function test_it_refuses_a_name_another_method_has(): void
    {
        $card = PaymentMethodMother::named('Card');
        $this->expectException(PaymentMethodNameTakenException::class);

        PaymentMethod::create(Uuid::v7(), 'Card', $card, 1, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_renames_a_method_and_keeps_its_own_name(): void
    {
        $method = PaymentMethodMother::named('Card');

        $method->rename(' Card ', $method, $this->actorId, MembershipRole::Owner);
        self::assertSame('Card', $method->getName());

        $method->rename('Card (terminal)', null, $this->actorId, MembershipRole::Manager);
        self::assertSame('Card (terminal)', $method->getName());

        $this->expectException(PaymentMethodNameTakenException::class);
        $method->rename('Cash', PaymentMethodMother::named('Cash'), $this->actorId, MembershipRole::Owner);
    }

    public function test_it_deactivates_once_and_reactivates_at_the_end(): void
    {
        $method = PaymentMethodMother::named('Voucher', 3);
        $first = new \DateTimeImmutable(self::NOW);

        $method->deactivate($this->actorId, MembershipRole::Manager, $first);
        $method->deactivate($this->actorId, MembershipRole::Manager, $first->modify('+1 day'));
        self::assertFalse($method->isActive());
        self::assertEquals($first, $method->getDeactivatedAt());

        $method->reactivate(9, $this->actorId, MembershipRole::Owner);
        self::assertTrue($method->isActive());
        self::assertNull($method->getDeactivatedAt());
        self::assertSame(9, $method->getPosition());

        $method->reactivate(12, $this->actorId, MembershipRole::Owner);
        self::assertSame(9, $method->getPosition());
    }

    public function test_it_lets_only_an_unused_method_be_deleted(): void
    {
        $method = PaymentMethodMother::named('Transfer');

        $method->assertDeletable(false, $this->actorId, MembershipRole::Manager);

        try {
            $method->assertDeletable(true, $this->actorId, MembershipRole::Owner);
            self::fail('A used method was deletable.');
        } catch (PaymentMethodInUseException $e) {
            self::assertSame('income.payment_method_in_use', $e->errorCode());
            self::assertSame(['paymentMethodId' => $method->getId()->toRfc4122(), 'name' => 'Transfer'], $e->errorParameters());
        }
    }

    /** @return iterable<string, array{\Closure(PaymentMethod, Uuid): mixed}> */
    public static function employeeActions(): iterable
    {
        yield 'create' => [static fn (PaymentMethod $method, Uuid $actorId): PaymentMethod => PaymentMethod::create(Uuid::v7(), 'BLIK', null, 0, $actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW))];
        yield 'rename' => [static fn (PaymentMethod $method, Uuid $actorId) => $method->rename('BLIK', null, $actorId, MembershipRole::Employee)];
        yield 'deactivate' => [static fn (PaymentMethod $method, Uuid $actorId) => $method->deactivate($actorId, MembershipRole::Employee, new \DateTimeImmutable(self::NOW))];
        yield 'reactivate' => [static fn (PaymentMethod $method, Uuid $actorId) => $method->reactivate(1, $actorId, MembershipRole::Employee)];
        yield 'delete' => [static fn (PaymentMethod $method, Uuid $actorId) => $method->assertDeletable(false, $actorId, MembershipRole::Employee)];
        yield 'reorder' => [static fn (PaymentMethod $method, Uuid $actorId) => PaymentMethodOrder::apply([$method], [$method->getId()->toRfc4122()], $actorId, MembershipRole::Employee)];
    }

    /** @param \Closure(PaymentMethod, Uuid): mixed $action */
    #[DataProvider('employeeActions')]
    public function test_it_lets_an_employee_manage_no_method(\Closure $action): void
    {
        $method = PaymentMethodMother::named('Cash');
        $this->expectException(IncomeManagementForbiddenException::class);

        $action($method, $this->actorId);
    }

    public function test_it_orders_active_methods_first_then_by_position(): void
    {
        $cash = PaymentMethodMother::named('Cash', 2);
        $card = PaymentMethodMother::named('Card', 5);
        $old = PaymentMethodMother::deactivated('Old', 0);
        $blik = PaymentMethodMother::named('BLIK', 0);
        $methods = [$old, $card, $cash, $blik];

        usort($methods, PaymentMethod::compare(...));

        self::assertSame([$blik, $cash, $card, $old], $methods);
    }

    public function test_it_puts_the_active_methods_in_a_new_order(): void
    {
        $cash = PaymentMethodMother::named('Cash', 0);
        $card = PaymentMethodMother::named('Card', 1);
        $blik = PaymentMethodMother::named('BLIK', 7);

        PaymentMethodOrder::apply([$cash, $card, $blik], [$blik->getId()->toRfc4122(), strtoupper($cash->getId()->toRfc4122()), $card->getId()->toRfc4122()], $this->actorId, MembershipRole::Manager);

        self::assertSame([0, 1, 2], [$blik->getPosition(), $cash->getPosition(), $card->getPosition()]);
    }

    /** @return iterable<string, array{\Closure(PaymentMethod, PaymentMethod): list<string>}> */
    public static function mismatchedOrders(): iterable
    {
        yield 'one missing' => [static fn (PaymentMethod $cash, PaymentMethod $card): array => [$cash->getId()->toRfc4122()]];
        yield 'one extra' => [static fn (PaymentMethod $cash, PaymentMethod $card): array => [$cash->getId()->toRfc4122(), $card->getId()->toRfc4122(), Uuid::v7()->toRfc4122()]];
        yield 'one twice' => [static fn (PaymentMethod $cash, PaymentMethod $card): array => [$cash->getId()->toRfc4122(), $cash->getId()->toRfc4122()]];
        yield 'another method' => [static fn (PaymentMethod $cash, PaymentMethod $card): array => [$cash->getId()->toRfc4122(), Uuid::v7()->toRfc4122()]];
    }

    /** @param \Closure(PaymentMethod, PaymentMethod): list<string> $ids */
    #[DataProvider('mismatchedOrders')]
    public function test_it_refuses_an_order_that_is_not_exactly_the_active_methods(\Closure $ids): void
    {
        $cash = PaymentMethodMother::named('Cash', 0);
        $card = PaymentMethodMother::named('Card', 1);

        try {
            PaymentMethodOrder::apply([$cash, $card], $ids($cash, $card), $this->actorId, MembershipRole::Owner);
            self::fail('The order was accepted.');
        } catch (PaymentMethodOrderMismatchException $e) {
            self::assertSame(2, $e->errorParameters()['activeCount']);
        }

        self::assertSame([0, 1], [$cash->getPosition(), $card->getPosition()]);
    }

    public function test_it_names_the_default_methods_by_country(): void
    {
        self::assertSame(['Gotówka', 'Karta', 'Przelew', 'Bon'], DefaultPaymentMethods::forCountry('pl'));
        self::assertSame(['Cash', 'Card', 'Transfer', 'Voucher'], DefaultPaymentMethods::forCountry('DE'));
    }
}
