<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\DecimalString;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceItem;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** The items of an imported invoice (ADR 0040): kept as printed, checked by the aggregate. */
#[CoversClass(PurchaseInvoice::class)]
#[CoversClass(PurchaseInvoiceItem::class)]
#[CoversClass(DecimalString::class)]
final class PurchaseInvoiceItemTest extends TestCase
{
    public function test_it_imports_items_trimmed_with_decimals_normalized_ordered_by_position(): void
    {
        $invoice = self::import(
            new PurchaseInvoiceItemDetails(position: 2, name: ' Woda mineralna ', sellerCode: ' ', unit: 'but.', quantity: '12.000000', unitGrossPrice: '2.50000000', discount: '+1.5', grossAmount: 2_850, vatAmount: 211, vatRate: '8'),
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', sellerCode: 'MIE-0042', gtin: '5901234123457', unit: 'kg', quantity: '0.700', unitNetPrice: '012.34567891', netAmount: 702, vatRate: ' 23 '),
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica (przed korektą)', quantity: '-0.000', beforeCorrection: true),
        );

        self::assertEquals([
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', sellerCode: 'MIE-0042', gtin: '5901234123457', unit: 'kg', quantity: '0.7', unitNetPrice: '12.34567891', netAmount: 702, vatRate: '23'),
            // The same position again: kept, after the first in the document's order.
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica (przed korektą)', quantity: '0', beforeCorrection: true),
            new PurchaseInvoiceItemDetails(position: 2, name: 'Woda mineralna', unit: 'but.', quantity: '12', unitGrossPrice: '2.5', discount: '1.5', grossAmount: 2_850, vatAmount: 211, vatRate: '8'),
        ], $invoice->getItems());
    }

    public function test_it_keeps_negative_quantities_and_amounts_of_a_correction(): void
    {
        $invoice = self::import(new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo (zwrot)', quantity: '-0.4', unitNetPrice: '250', netAmount: -10_000, vatRate: '23'));

        self::assertSame('-0.4', $invoice->getItems()[0]->quantity);
        self::assertSame(-10_000, $invoice->getItems()[0]->netAmount);
    }

    public function test_it_does_not_check_items_against_the_totals(): void
    {
        $invoice = self::import(new PurchaseInvoiceItemDetails(position: 1, name: 'Anything', netAmount: 999_999));

        self::assertSame(20_000, $invoice->getNetTotal());
        self::assertSame(999_999, $invoice->getItems()[0]->netAmount);
    }

    public function test_it_has_no_items_without_them(): void
    {
        self::assertSame([], PurchaseInvoiceMother::imported()->getItems());
    }

    /** @return iterable<string, array{PurchaseInvoiceItemDetails, string}> */
    public static function invalidItems(): iterable
    {
        yield 'blank name' => [new PurchaseInvoiceItemDetails(position: 1, name: '  '), 'item name'];
        yield 'name too long' => [new PurchaseInvoiceItemDetails(position: 1, name: str_repeat('a', 513)), 'item name'];
        yield 'seller code too long' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', sellerCode: str_repeat('a', 65)), 'item seller code'];
        yield 'GTIN too long' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', gtin: str_repeat('1', 33)), 'item GTIN'];
        yield 'unit too long' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', unit: str_repeat('a', 257)), 'item unit'];
        yield 'position 0' => [new PurchaseInvoiceItemDetails(position: 0, name: 'A'), 'item position'];
        yield 'quantity with a comma' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', quantity: '0,7'), 'item quantity'];
        yield 'quantity in exponent form' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', quantity: '7e-1'), 'item quantity'];
        yield 'quantity with seven decimals' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', quantity: '0.1234567'), 'item quantity'];
        yield 'quantity with 17 integer digits' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', quantity: str_repeat('9', 17)), 'item quantity'];
        yield 'unit price with nine decimals' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', unitNetPrice: '1.123456789'), 'item unit net price'];
        yield 'unit gross price with 15 integer digits' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', unitGrossPrice: str_repeat('9', 15)), 'item unit gross price'];
        yield 'discount that is not a number' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', discount: 'ten'), 'item discount'];
        yield 'unknown VAT rate' => [new PurchaseInvoiceItemDetails(position: 1, name: 'A', vatRate: '22'), '"22"'];
    }

    #[DataProvider('invalidItems')]
    public function test_it_rejects_an_invalid_item(PurchaseInvoiceItemDetails $item, string $message): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);
        $this->expectExceptionMessage($message);

        self::import($item);
    }

    public function test_it_accepts_the_longest_texts_and_most_digits(): void
    {
        $invoice = self::import(new PurchaseInvoiceItemDetails(
            position: 1,
            name: str_repeat('ą', 512),
            sellerCode: str_repeat('a', 64),
            gtin: str_repeat('1', 32),
            unit: str_repeat('a', 256),
            quantity: '-'.str_repeat('9', 16).'.123456',
            unitNetPrice: str_repeat('9', 14).'.12345678',
        ));

        self::assertSame('-9999999999999999.123456', $invoice->getItems()[0]->quantity);
        self::assertSame('99999999999999.12345678', $invoice->getItems()[0]->unitNetPrice);
    }

    public function test_it_refuses_items_on_a_manual_invoice(): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);
        $this->expectExceptionMessage('An invoice entered by hand has no items.');

        PurchaseInvoice::enter(Uuid::v7(), self::details([new PurchaseInvoiceItemDetails(position: 1, name: 'A')]), 'PL', 'PLN', Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable('2026-11-12'));
    }

    public function test_it_refuses_items_when_changing_a_manual_invoice(): void
    {
        $invoice = PurchaseInvoiceMother::manual();

        $this->expectException(InvalidPurchaseInvoiceException::class);
        $this->expectExceptionMessage('An invoice entered by hand has no items.');

        $invoice->change(self::details([new PurchaseInvoiceItemDetails(position: 1, name: 'A')]), 'PL', Uuid::v7(), MembershipRole::Owner);
    }

    public function test_it_keeps_the_items_of_an_imported_invoice_given_its_own_details_without_them(): void
    {
        $invoice = self::import(new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo'));

        $invoice->change(self::details(), 'PL', Uuid::v7(), MembershipRole::Owner);

        self::assertCount(1, $invoice->getItems());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function decimals(): iterable
    {
        yield 'integer' => ['4', '4'];
        yield 'trailing zeros' => ['0.700000', '0.7'];
        yield 'leading zeros' => ['007.50', '7.5'];
        yield 'only zeros' => ['000.000', '0'];
        yield 'negative zero' => ['-0.0', '0'];
        yield 'negative' => ['-12.345678', '-12.345678'];
        yield 'plus sign' => ['+3', '3'];
        yield 'white space' => [' 1.5 ', '1.5'];
        yield 'no integer part' => ['.5', null];
        yield 'no fraction' => ['5.', null];
        yield 'too many decimals' => ['0.1234567', null];
    }

    #[DataProvider('decimals')]
    public function test_it_normalizes_a_decimal_string(string $value, ?string $normalized): void
    {
        self::assertSame($normalized, DecimalString::normalized($value, 16, 6));
    }

    private static function import(PurchaseInvoiceItemDetails ...$items): PurchaseInvoice
    {
        return PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-1', self::details(array_values($items)), 'PL', 'PLN', new \DateTimeImmutable('2026-11-12 21:00:00'));
    }

    /** @param list<PurchaseInvoiceItemDetails> $items */
    private static function details(array $items = []): PurchaseInvoiceDetails
    {
        return new PurchaseInvoiceDetails('FV/1/2026', '5265877635', 'Hurtownia', BusinessDate::fromString('2026-11-10'), PurchaseInvoiceKind::Regular, 'PLN', 20_000, 4_600, 24_600, items: $items);
    }
}
