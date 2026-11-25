<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PurchaseInvoice::class)]
#[CoversClass(PurchaseInvoiceKind::class)]
final class PurchaseInvoiceTest extends TestCase
{
    private const string NOW = '2026-11-12 21:00:00';

    public function test_it_imports_an_invoice_with_its_details_trimmed_and_vat_lines_by_rate(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $invoice = PurchaseInvoice::import($id, PurchaseInvoiceSource::Ksef, '  5265877635-20261110-0100A0B1C2D3-4E  ', self::details(
            invoiceNumber: ' FV/123/11/2026 ',
            sellerName: ' Hurtownia Napojów sp. z o.o. ',
            currency: 'pln',
            vatLines: [new PurchaseInvoiceVatAmount('8', 10_000, 800), new PurchaseInvoiceVatAmount('zw', 500, 0), new PurchaseInvoiceVatAmount('23', 20_000, 4_600)],
        ), 'PL', 'PLN', $now);

        self::assertSame($id, $invoice->getId());
        self::assertSame(PurchaseInvoiceSource::Ksef, $invoice->getSource());
        self::assertSame('5265877635-20261110-0100A0B1C2D3-4E', $invoice->getExternalId());
        self::assertSame('FV/123/11/2026', $invoice->getInvoiceNumber());
        self::assertSame('5265877635', $invoice->getSellerTaxId());
        self::assertSame('Hurtownia Napojów sp. z o.o.', $invoice->getSellerName());
        self::assertSame('2026-11-10', $invoice->getIssueDate()->toString());
        self::assertSame(PurchaseInvoiceKind::Regular, $invoice->getKind());
        self::assertNull($invoice->getCorrectedExternalId());
        self::assertSame('PLN', $invoice->getCurrency());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        self::assertSame(30_500, $invoice->getNetTotal());
        self::assertSame(5_400, $invoice->getVatTotal());
        self::assertSame(35_900, $invoice->getGrossTotal());
        self::assertEquals(
            [new PurchaseInvoiceVatAmount('23', 20_000, 4_600), new PurchaseInvoiceVatAmount('8', 10_000, 800), new PurchaseInvoiceVatAmount('zw', 500, 0)],
            $invoice->getVatLines(),
        );
        self::assertSame($now, $invoice->getCreatedAt());
    }

    public function test_it_keeps_a_corrections_negative_amounts_and_the_corrected_invoice(): void
    {
        $invoice = PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-2', self::details(
            kind: PurchaseInvoiceKind::Correction,
            netTotal: -1_000,
            vatTotal: -230,
            grossTotal: -1_230,
            vatLines: [new PurchaseInvoiceVatAmount('23', -1_000, -230)],
            correctedExternalId: ' K-1 ',
        ), 'PL', 'PLN', new \DateTimeImmutable(self::NOW));

        self::assertSame(-1_230, $invoice->getGrossTotal());
        self::assertEquals([new PurchaseInvoiceVatAmount('23', -1_000, -230)], $invoice->getVatLines());
        self::assertSame('K-1', $invoice->getCorrectedExternalId());
    }

    public function test_it_stores_totals_as_given_without_checking_them_against_each_other_or_the_lines(): void
    {
        // An advance invoice: the lines are the advance, the gross what was paid.
        $invoice = PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-3', self::details(
            kind: PurchaseInvoiceKind::Advance,
            netTotal: 1,
            vatTotal: 2,
            grossTotal: 100,
            vatLines: [new PurchaseInvoiceVatAmount('23', 5_000, 1_150)],
        ), 'PL', 'PLN', new \DateTimeImmutable(self::NOW));

        self::assertSame([1, 2, 100], [$invoice->getNetTotal(), $invoice->getVatTotal(), $invoice->getGrossTotal()]);
    }

    public function test_it_takes_an_invoice_without_vat_lines(): void
    {
        $invoice = PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-4', self::details(kind: PurchaseInvoiceKind::Simplified, vatLines: []), 'PL', 'PLN', new \DateTimeImmutable(self::NOW));

        self::assertSame([], $invoice->getVatLines());
    }

    public function test_it_takes_an_invoice_in_another_currency_with_the_vat_also_in_the_tax_currency(): void
    {
        $invoice = PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-5', self::details(
            currency: 'EUR',
            vatLines: [new PurchaseInvoiceVatAmount('23', 10_000, 2_300, 9_775), new PurchaseInvoiceVatAmount('0', 1_000, 0, 0)],
        ), 'PL', 'pln', new \DateTimeImmutable(self::NOW));

        self::assertSame('EUR', $invoice->getCurrency());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        self::assertEquals(
            [new PurchaseInvoiceVatAmount('23', 10_000, 2_300, 9_775), new PurchaseInvoiceVatAmount('0', 1_000, 0, 0)],
            $invoice->getVatLines(),
        );
    }

    public function test_it_does_not_import_with_the_manual_source(): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);

        PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Manual, 'X-1', self::details(), 'PL', 'PLN', new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{string, PurchaseInvoiceDetails, string, string}> */
    public static function invalidInvoices(): iterable
    {
        yield 'blank external id' => [' ', self::details(), 'PL', 'PLN'];
        yield 'external id too long' => [str_repeat('k', 65), self::details(), 'PL', 'PLN'];
        yield 'blank invoice number' => ['K', self::details(invoiceNumber: '  '), 'PL', 'PLN'];
        yield 'invoice number too long' => ['K', self::details(invoiceNumber: str_repeat('n', 257)), 'PL', 'PLN'];
        yield 'blank seller tax ID' => ['K', self::details(sellerTaxId: ''), 'PL', 'PLN'];
        yield 'seller tax ID too long' => ['K', self::details(sellerTaxId: str_repeat('1', 33)), 'PL', 'PLN'];
        yield 'blank seller name' => ['K', self::details(sellerName: ' '), 'PL', 'PLN'];
        yield 'seller name too long' => ['K', self::details(sellerName: str_repeat('ż', 513)), 'PL', 'PLN'];
        yield 'currency not a code' => ['K', self::details(currency: 'ZŁ'), 'PL', 'PLN'];
        yield 'tax currency not a code' => ['K', self::details(), 'PL', 'PLNX'];
        yield 'corrected invoice on a regular invoice' => ['K', self::details(correctedExternalId: 'K-1'), 'PL', 'PLN'];
        yield 'corrected invoice id too long' => ['K', self::details(kind: PurchaseInvoiceKind::Correction, correctedExternalId: str_repeat('k', 65)), 'PL', 'PLN'];
        yield 'rate unknown in the country' => ['K', self::details(vatLines: [new PurchaseInvoiceVatAmount('7', 100, 7)]), 'PL', 'PLN'];
        yield 'rates in a country without rates' => ['K', self::details(vatLines: [new PurchaseInvoiceVatAmount('23', 100, 23)]), 'DE', 'EUR'];
        yield 'rate twice' => ['K', self::details(vatLines: [new PurchaseInvoiceVatAmount('23', 100, 23), new PurchaseInvoiceVatAmount('23', 200, 46)]), 'PL', 'PLN'];
        yield 'VAT on an exempt rate' => ['K', self::details(vatLines: [new PurchaseInvoiceVatAmount('zw', 100, 1)]), 'PL', 'PLN'];
        yield 'VAT in the tax currency on an exempt rate' => ['K', self::details(currency: 'EUR', vatLines: [new PurchaseInvoiceVatAmount('zw', 100, 0, 1)]), 'PL', 'PLN'];
        yield 'VAT in the tax currency missing' => ['K', self::details(currency: 'EUR', vatLines: [new PurchaseInvoiceVatAmount('23', 100, 23)]), 'PL', 'PLN'];
        yield 'VAT in the tax currency on an invoice in it' => ['K', self::details(vatLines: [new PurchaseInvoiceVatAmount('23', 100, 23, 23)]), 'PL', 'PLN'];
    }

    #[DataProvider('invalidInvoices')]
    public function test_it_rejects_invalid_details(string $externalId, PurchaseInvoiceDetails $details, string $countryCode, string $taxCurrency): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);

        PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, $externalId, $details, $countryCode, $taxCurrency, new \DateTimeImmutable(self::NOW));
    }

    public function test_it_takes_an_invoice_without_vat_lines_in_a_country_without_rates(): void
    {
        $invoice = PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K', self::details(currency: 'EUR', vatLines: []), 'DE', 'EUR', new \DateTimeImmutable(self::NOW));

        self::assertSame('EUR', $invoice->getTaxCurrency());
    }

    public function test_it_knows_which_kinds_are_corrections(): void
    {
        $corrections = array_values(array_filter(PurchaseInvoiceKind::cases(), static fn (PurchaseInvoiceKind $kind): bool => $kind->isCorrection()));

        self::assertSame([PurchaseInvoiceKind::Correction, PurchaseInvoiceKind::AdvanceCorrection, PurchaseInvoiceKind::SettlementCorrection], $corrections);
    }

    /** @param list<PurchaseInvoiceVatAmount> $vatLines */
    private static function details(
        string $invoiceNumber = 'FV/1/2026',
        string $sellerTaxId = '5265877635',
        string $sellerName = 'Hurtownia',
        PurchaseInvoiceKind $kind = PurchaseInvoiceKind::Regular,
        string $currency = 'PLN',
        int $netTotal = 30_500,
        int $vatTotal = 5_400,
        int $grossTotal = 35_900,
        array $vatLines = [new PurchaseInvoiceVatAmount('23', 20_000, 4_600)],
        ?string $correctedExternalId = null,
    ): PurchaseInvoiceDetails {
        return new PurchaseInvoiceDetails($invoiceNumber, $sellerTaxId, $sellerName, BusinessDate::fromString('2026-11-10'), $kind, $currency, $netTotal, $vatTotal, $grossTotal, $vatLines, $correctedExternalId);
    }
}
