<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Domain;

use App\Income\Domain\Exception\IncomeManagementForbiddenException;
use App\Income\Domain\Exception\InvalidPaymentLinesException;
use App\Income\Domain\Exception\InvalidVatLinesException;
use App\Income\Domain\Exception\RegisterDeactivatedException;
use App\Income\Domain\Exception\RevenueEntryInTheFutureException;
use App\Income\Domain\Model\PaymentAmount;
use App\Income\Domain\Model\PaymentMethod;
use App\Income\Domain\Model\Register;
use App\Income\Domain\Model\RevenueEntry;
use App\Income\Domain\Model\VatAmount;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\PaymentMethodMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RevenueEntry::class)]
final class RevenueEntryTest extends TestCase
{
    private const string TODAY = '2026-11-12';
    private const string NOW = '2026-11-12 10:00:00';
    private const string LATER = '2026-11-13 09:30:00';

    private Uuid $actorId;
    private Register $register;

    protected function setUp(): void
    {
        $this->actorId = Uuid::v7();
        $this->register = Register::create(Uuid::v7(), 'Bar', null, $this->actorId, MembershipRole::Owner, new \DateTimeImmutable('2026-01-01'));
    }

    public function test_it_records_a_daily_report(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $entry = RevenueEntry::record(
            $id,
            $this->register,
            BusinessDate::fromString('2026-11-11'),
            'PLN',
            'PL',
            [$this->card(250_000), $this->cash(120_050)],
            [new VatAmount('8', 100_000, 7_407), new VatAmount('23', 270_050, 50_497)],
            '  Busy Wednesday  ',
            $this->actorId,
            MembershipRole::Manager,
            BusinessDate::fromString(self::TODAY),
            $now,
        );

        self::assertSame($id, $entry->getId());
        self::assertSame($this->register, $entry->getRegister());
        self::assertSame('2026-11-11', $entry->getBusinessDate()->toString());
        self::assertSame('PLN', $entry->getCurrency());
        self::assertEquals([$this->cash(120_050), $this->card(250_000)], $entry->getPaymentLines());
        self::assertEquals([new VatAmount('23', 270_050, 50_497), new VatAmount('8', 100_000, 7_407)], $entry->getVatLines());
        self::assertSame(370_050, $entry->getTotal());
        self::assertSame('Busy Wednesday', $entry->getNote());
        self::assertSame($this->actorId, $entry->getCreatedBy());
        self::assertSame($now, $entry->getCreatedAt());
        self::assertNull($entry->getUpdatedBy());
        self::assertNull($entry->getUpdatedAt());
    }

    public function test_it_records_today_without_a_vat_breakdown_or_a_note(): void
    {
        $entry = $this->record([$this->cash(0)], [], businessDate: self::TODAY, note: '  ');

        self::assertSame([], $entry->getVatLines());
        self::assertSame(0, $entry->getTotal());
        self::assertNull($entry->getNote());
    }

    public function test_it_lets_an_employee_record_no_entry(): void
    {
        $this->expectException(IncomeManagementForbiddenException::class);

        $this->record([$this->cash(100)], [], role: MembershipRole::Employee);
    }

    public function test_it_takes_no_new_entry_on_a_deactivated_register(): void
    {
        $this->register->deactivate($this->actorId, MembershipRole::Owner, new \DateTimeImmutable('2026-11-01'));

        $this->expectException(RegisterDeactivatedException::class);

        $this->record([$this->cash(100)], []);
    }

    public function test_it_rejects_a_business_date_after_today(): void
    {
        $this->expectException(RevenueEntryInTheFutureException::class);

        $this->record([$this->cash(100)], [], businessDate: '2026-11-13');
    }

    /** @param list<PaymentAmount> $payments */
    #[DataProvider('invalidPayments')]
    public function test_it_rejects_invalid_payment_lines(array $payments): void
    {
        $this->expectException(InvalidPaymentLinesException::class);

        $this->record($payments, []);
    }

    /** @return iterable<string, array{list<PaymentAmount>}> */
    public static function invalidPayments(): iterable
    {
        yield 'no lines' => [[]];
        yield 'negative amount' => [[new PaymentAmount(self::method('Cash'), -1)]];
        yield 'amount over the column' => [[new PaymentAmount(self::method('Cash'), RevenueEntry::MAX_AMOUNT + 1)]];
        yield 'method twice' => [[new PaymentAmount(self::method('Card'), 100), new PaymentAmount(self::method('Card'), 200)]];
        yield 'deactivated method' => [[new PaymentAmount(PaymentMethodMother::deactivated('Meal vouchers'), 100)]];
    }

    public function test_it_accepts_every_payment_method_once_with_the_largest_amount(): void
    {
        $payments = array_map(static fn (string $name): PaymentAmount => new PaymentAmount(self::method($name), RevenueEntry::MAX_AMOUNT), ['Cash', 'Card', 'Transfer', 'Voucher']);

        $entry = $this->record($payments, []);

        self::assertEquals($payments, $entry->getPaymentLines());
        self::assertSame(4 * RevenueEntry::MAX_AMOUNT, $entry->getTotal());
    }

    /** @param list<VatAmount> $vat */
    #[DataProvider('invalidVat')]
    public function test_it_rejects_invalid_vat_lines(array $vat, string $countryCode = 'PL'): void
    {
        $this->expectException(InvalidVatLinesException::class);

        $this->record([$this->cash(100)], $vat, countryCode: $countryCode);
    }

    /** @return iterable<string, array{0: list<VatAmount>, 1?: string}> */
    public static function invalidVat(): iterable
    {
        yield 'country without rates' => [[new VatAmount('20', 1_000, 167)], 'GB'];
        yield 'unknown rate' => [[new VatAmount('22', 1_000, 180)]];
        yield 'rate twice' => [[new VatAmount('23', 1_000, 187), new VatAmount('23', 500, 93)]];
        yield 'negative gross' => [[new VatAmount('23', -1, 0)]];
        yield 'gross over the column' => [[new VatAmount('23', RevenueEntry::MAX_AMOUNT + 1, 0)]];
        yield 'negative VAT' => [[new VatAmount('23', 1_000, -1)]];
        yield 'VAT over gross' => [[new VatAmount('23', 1_000, 1_001)]];
        yield 'VAT on 0%' => [[new VatAmount('0', 1_000, 1)]];
        yield 'VAT on exempt' => [[new VatAmount('zw', 1_000, 1)]];
    }

    public function test_it_accepts_vat_as_printed_even_when_it_does_not_match_the_payments(): void
    {
        $vat = [
            new VatAmount('23', 1_000, 1_000),
            new VatAmount('8', 0, 0),
            new VatAmount('5', 2_000, 95),
            new VatAmount('0', 3_000, 0),
            new VatAmount('zw', 4_000, 0),
        ];

        $entry = $this->record([$this->cash(1)], $vat);

        self::assertEquals($vat, $entry->getVatLines());
        self::assertSame(1, $entry->getTotal());
        self::assertSame(1_905, $vat[2]->net());
    }

    public function test_it_takes_no_vat_lines_but_any_payment_lines_in_a_country_without_rates(): void
    {
        $entry = $this->record([$this->cash(100)], [], countryCode: 'GB');

        self::assertSame(100, $entry->getTotal());
    }

    public function test_it_changes_the_lines_and_the_note_and_records_who_did(): void
    {
        $entry = $this->record([$this->cash(100), $this->card(200)], [new VatAmount('23', 300, 56)]);
        $editorId = Uuid::v7();
        $later = new \DateTimeImmutable(self::LATER);

        $entry->change(
            [$this->card(250), new PaymentAmount(self::method('Voucher'), 50)],
            [new VatAmount('8', 300, 22)],
            'Voucher found',
            'PL',
            $editorId,
            MembershipRole::Manager,
            $later,
        );

        self::assertEquals([$this->card(250), new PaymentAmount(self::method('Voucher'), 50)], $entry->getPaymentLines());
        self::assertEquals([new VatAmount('8', 300, 22)], $entry->getVatLines());
        self::assertSame(300, $entry->getTotal());
        self::assertSame('Voucher found', $entry->getNote());
        self::assertSame($editorId, $entry->getUpdatedBy());
        self::assertSame($later, $entry->getUpdatedAt());
        self::assertSame($this->actorId, $entry->getCreatedBy());
    }

    public function test_it_changes_nothing_when_the_entry_already_has_the_values(): void
    {
        $entry = $this->record([$this->cash(100), $this->card(200)], [new VatAmount('23', 300, 56)], note: 'Same');

        $entry->change([$this->card(200), $this->cash(100)], [new VatAmount('23', 300, 56)], ' Same ', 'PL', Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::LATER));

        self::assertNull($entry->getUpdatedBy());
        self::assertNull($entry->getUpdatedAt());
    }

    public function test_it_changes_an_entry_of_a_deactivated_register(): void
    {
        $entry = $this->record([$this->cash(100)], []);
        $this->register->deactivate($this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

        $entry->change([$this->cash(150)], [], null, 'PL', $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));

        self::assertSame(150, $entry->getTotal());
    }

    public function test_it_lets_an_employee_change_no_entry(): void
    {
        $entry = $this->record([$this->cash(100)], []);

        $this->expectException(IncomeManagementForbiddenException::class);

        $entry->change([$this->cash(150)], [], null, 'PL', Uuid::v7(), MembershipRole::Employee, new \DateTimeImmutable(self::LATER));
    }

    public function test_it_keeps_the_line_of_a_payment_method_deactivated_since_but_takes_no_new_one(): void
    {
        $meal = PaymentMethodMother::named('Meal vouchers', 4);
        $delivery = PaymentMethodMother::named('Delivery', 5);
        $entry = $this->record([$this->cash(100), new PaymentAmount($meal, 40)], []);
        $meal->deactivate($this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));
        $delivery->deactivate($this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));

        $entry->change([$this->cash(100), new PaymentAmount($meal, 60)], [], null, 'PL', $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));
        self::assertSame(160, $entry->getTotal());

        try {
            $entry->change([$this->cash(100), new PaymentAmount($meal, 60), new PaymentAmount($delivery, 1)], [], null, 'PL', $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));
            self::fail('A new line of a deactivated method was accepted.');
        } catch (InvalidPaymentLinesException $e) {
            self::assertSame('income.invalid_payment_lines.method_deactivated', $e->errorCode());
        }
        self::assertSame(160, $entry->getTotal());
    }

    public function test_it_orders_the_lines_as_the_organization_orders_its_methods_with_deactivated_ones_last(): void
    {
        $first = PaymentMethodMother::named('BLIK', -1);
        $last = PaymentMethodMother::named('Old terminal', -2);

        $entry = $this->record([$this->card(1), new PaymentAmount($last, 4), $this->cash(2), new PaymentAmount($first, 3)], []);
        $last->deactivate($this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));

        self::assertEquals([new PaymentAmount($first, 3), $this->cash(2), $this->card(1), new PaymentAmount($last, 4)], $entry->getPaymentLines());
    }

    public function test_it_checks_the_lines_of_a_change(): void
    {
        $entry = $this->record([$this->cash(100)], []);

        try {
            $entry->change([], [], null, 'PL', $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));
            self::fail('An entry without payment lines was accepted.');
        } catch (InvalidPaymentLinesException) {
        }

        $this->expectException(InvalidVatLinesException::class);

        $entry->change([$this->cash(100)], [new VatAmount('zw', 100, 5)], null, 'PL', $this->actorId, MembershipRole::Owner, new \DateTimeImmutable(self::LATER));
    }

    /**
     * @param list<PaymentAmount> $payments
     * @param list<VatAmount>     $vat
     */
    private function record(
        array $payments,
        array $vat,
        string $businessDate = '2026-11-11',
        MembershipRole $role = MembershipRole::Owner,
        string $countryCode = 'PL',
        ?string $note = null,
    ): RevenueEntry {
        return RevenueEntry::record(
            Uuid::v7(),
            $this->register,
            BusinessDate::fromString($businessDate),
            'PLN',
            $countryCode,
            $payments,
            $vat,
            $note,
            $this->actorId,
            $role,
            BusinessDate::fromString(self::TODAY),
            new \DateTimeImmutable(self::NOW),
        );
    }

    /**
     * The organization's methods, one object per name for the whole class (data providers run
     * before setUp()), in the order cash, card, transfer, voucher.
     */
    private static function method(string $name): PaymentMethod
    {
        static $methods = [];

        return $methods[$name] ??= PaymentMethodMother::named($name, (int) array_search($name, ['Cash', 'Card', 'Transfer', 'Voucher'], true));
    }

    private function cash(int $amount): PaymentAmount
    {
        return new PaymentAmount(self::method('Cash'), $amount);
    }

    private function card(int $amount): PaymentAmount
    {
        return new PaymentAmount(self::method('Card'), $amount);
    }
}
