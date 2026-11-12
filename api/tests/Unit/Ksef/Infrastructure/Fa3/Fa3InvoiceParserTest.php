<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ksef\Infrastructure\Fa3;

use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Ksef\Domain\Exception\InvalidFa3InvoiceException;
use App\Ksef\Domain\Exception\KsefException;
use App\Ksef\Domain\Exception\UnsupportedInvoiceFormException;
use App\Ksef\Infrastructure\Fa3\Fa3InvoiceParser;
use App\Shared\Domain\Time\BusinessDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The FA(3) parser against anonymised samples that validate against schemat_FA(3)_v1-0E.xsd
 * (tests/Fixtures/Ksef/README.md).
 */
final class Fa3InvoiceParserTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../Fixtures/Ksef/';

    public function test_it_reads_a_vat_invoice_with_several_rates(): void
    {
        $details = $this->parse('Fa3/vat-several-rates.xml');

        self::assertSame('FV/2026/11/0042', $details->invoiceNumber);
        self::assertSame('4882338469', $details->sellerTaxId);
        self::assertSame('Hurtownia Testowa Sp. z o.o.', $details->sellerName);
        self::assertEquals(BusinessDate::of(2026, 11, 3), $details->issueDate);
        self::assertSame(PurchaseInvoiceKind::Regular, $details->kind);
        self::assertSame('PLN', $details->currency);
        self::assertSame(140279, $details->netTotal);
        self::assertSame(25504, $details->vatTotal);
        self::assertSame(165783, $details->grossTotal);
        self::assertEquals([
            new PurchaseInvoiceVatAmount('23', 100000, 23000),
            new PurchaseInvoiceVatAmount('8', 25050, 2004),
            new PurchaseInvoiceVatAmount('5', 9999, 500),
            new PurchaseInvoiceVatAmount('0', 4000, 0),
            new PurchaseInvoiceVatAmount('zw', 1230, 0),
        ], $details->vatLines);
        self::assertNull($details->correctedExternalId);
    }

    public function test_it_gives_each_line_of_an_invoice_in_another_currency_its_vat_in_pln(): void
    {
        $details = $this->parse('Fa3/foreign-currency.xml');

        self::assertSame('EUR', $details->currency);
        self::assertSame(61000, $details->netTotal);
        self::assertSame(12300, $details->vatTotal);
        self::assertSame(73300, $details->grossTotal);
        self::assertEquals([
            new PurchaseInvoiceVatAmount('23', 50000, 11500, 48990),
            new PurchaseInvoiceVatAmount('8', 10000, 800, 3408),
            new PurchaseInvoiceVatAmount('0', 1000, 0, 0),
        ], $details->vatLines);
    }

    public function test_it_reads_amounts_in_the_minor_units_of_the_invoices_currency(): void
    {
        $details = $this->parser()->parse($this->replace('Fa3/foreign-currency.xml', '<KodWaluty>EUR</KodWaluty>', '<KodWaluty>JPY</KodWaluty>'));

        self::assertSame('JPY', $details->currency);
        self::assertSame(610, $details->netTotal);
        self::assertSame(733, $details->grossTotal);
        // The VAT in PLN stays in grosze.
        self::assertEquals(new PurchaseInvoiceVatAmount('23', 500, 115, 48990), $details->vatLines[0]);
    }

    public function test_it_rejects_an_amount_its_currency_cannot_hold(): void
    {
        $xml = str_replace('<P_13_1>500.00</P_13_1>', '<P_13_1>500.50</P_13_1>', $this->replace('Fa3/foreign-currency.xml', '<KodWaluty>EUR</KodWaluty>', '<KodWaluty>JPY</KodWaluty>'));

        $this->expectException(InvalidFa3InvoiceException::class);
        $this->expectExceptionMessage('Fa/P_13_1');

        $this->parser()->parse($xml);
    }

    public function test_it_leaves_the_vat_in_pln_out_when_an_invoice_in_another_currency_omits_it(): void
    {
        $details = $this->parser()->parse($this->replace('Fa3/foreign-currency.xml', "<P_14_2W>34.08</P_14_2W>\n", ''));

        self::assertEquals(new PurchaseInvoiceVatAmount('8', 10000, 800, null), $details->vatLines[1]);

        // Expenses then refuses the invoice instead of storing it without the VAT in PLN.
        $this->expectException(InvalidPurchaseInvoiceException::class);
        $this->import($details);
    }

    public function test_it_keeps_the_negative_amounts_of_a_correction_and_the_corrected_ksef_number(): void
    {
        $details = $this->parse('Fa3/correction.xml');

        self::assertSame(PurchaseInvoiceKind::Correction, $details->kind);
        self::assertSame('FK/2026/11/0003', $details->invoiceNumber);
        self::assertSame(-10050, $details->netTotal);
        self::assertSame(-2304, $details->vatTotal);
        self::assertSame(-12354, $details->grossTotal);
        self::assertEquals([
            new PurchaseInvoiceVatAmount('23', -10000, -2300),
            new PurchaseInvoiceVatAmount('8', -50, -4),
        ], $details->vatLines);
        self::assertSame('4882338469-20261103-0A1B2C-3D4E5F-7A', $details->correctedExternalId);
    }

    public function test_it_has_no_corrected_ksef_number_for_an_invoice_corrected_outside_ksef(): void
    {
        $xml = $this->replace(
            'Fa3/correction.xml',
            "<NrKSeF>1</NrKSeF>\n\t\t\t<NrKSeFFaKorygowanej>4882338469-20261103-0A1B2C-3D4E5F-7A</NrKSeFFaKorygowanej>",
            '<NrKSeFN>1</NrKSeFN>',
        );

        self::assertNull($this->parser()->parse($xml)->correctedExternalId);
    }

    public function test_it_has_no_corrected_ksef_number_for_a_correction_of_several_invoices(): void
    {
        $xml = (string) file_get_contents(self::FIXTURES.'Fa3/correction.xml');
        self::assertSame(1, preg_match('#\t\t<DaneFaKorygowanej>.*?</DaneFaKorygowanej>\n#s', $xml, $block));
        $xml = str_replace($block[0], $block[0].str_replace('0A1B2C', '0A1B2D', $block[0]), $xml);

        self::assertNull($this->parser()->parse($xml)->correctedExternalId);
    }

    public function test_it_reads_an_advance_invoice(): void
    {
        $details = $this->parse('Fa3/advance.xml');

        self::assertSame(PurchaseInvoiceKind::Advance, $details->kind);
        self::assertSame('9466112522', $details->sellerTaxId);
        self::assertSame(80000, $details->netTotal);
        self::assertSame(18400, $details->vatTotal);
        self::assertSame(98400, $details->grossTotal);
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 80000, 18400)], $details->vatLines);
        self::assertNull($details->correctedExternalId);
    }

    public function test_it_counts_groups_without_a_pl_rate_code_in_the_totals_only(): void
    {
        $details = $this->parse('Fa3/unsupported-vat-groups.xml');

        // Taxi flat rate (P_13_4, P_14_4) and reverse charge (P_13_10) have no VatRates code.
        self::assertSame(55000, $details->netTotal);
        self::assertSame(4800, $details->vatTotal);
        self::assertSame(59800, $details->grossTotal);
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 20000, 4600)], $details->vatLines);
    }

    public function test_it_reads_the_invoice_the_client_test_downloads(): void
    {
        $details = $this->parse('invoice-fa3.xml');

        self::assertSame('FV/2026/11/0042', $details->invoiceNumber);
        self::assertSame('3947150208', $details->sellerTaxId);
        self::assertSame(123000, $details->grossTotal);
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 100000, 23000)], $details->vatLines);
    }

    /** @return iterable<string, array{string, PurchaseInvoiceKind}> */
    public static function kinds(): iterable
    {
        yield 'VAT' => ['VAT', PurchaseInvoiceKind::Regular];
        yield 'KOR' => ['KOR', PurchaseInvoiceKind::Correction];
        yield 'ZAL' => ['ZAL', PurchaseInvoiceKind::Advance];
        yield 'ROZ' => ['ROZ', PurchaseInvoiceKind::Settlement];
        yield 'UPR' => ['UPR', PurchaseInvoiceKind::Simplified];
        yield 'KOR_ZAL' => ['KOR_ZAL', PurchaseInvoiceKind::AdvanceCorrection];
        yield 'KOR_ROZ' => ['KOR_ROZ', PurchaseInvoiceKind::SettlementCorrection];
    }

    #[DataProvider('kinds')]
    public function test_it_maps_the_invoice_kind(string $code, PurchaseInvoiceKind $kind): void
    {
        $xml = $this->replace('Fa3/vat-several-rates.xml', '<RodzajFaktury>VAT</RodzajFaktury>', '<RodzajFaktury>'.$code.'</RodzajFaktury>');

        self::assertSame($kind, $this->parser()->parse($xml)->kind);
    }

    /** @return iterable<string, array{string}> */
    public static function samples(): iterable
    {
        yield 'several rates' => ['Fa3/vat-several-rates.xml'];
        yield 'another currency' => ['Fa3/foreign-currency.xml'];
        yield 'correction' => ['Fa3/correction.xml'];
        yield 'advance' => ['Fa3/advance.xml'];
        yield 'groups without a code' => ['Fa3/unsupported-vat-groups.xml'];
        yield 'client fixture' => ['invoice-fa3.xml'];
    }

    #[DataProvider('samples')]
    public function test_it_gives_expenses_an_invoice_it_stores_for_a_polish_organization(string $file): void
    {
        $details = $this->parse($file);

        $invoice = $this->import($details);

        self::assertSame($details->grossTotal, $invoice->getGrossTotal());
        self::assertSame($details->correctedExternalId, $invoice->getCorrectedExternalId());
        self::assertCount(\count($details->vatLines), $invoice->getVatLines());
    }

    /** @return iterable<string, array{\Closure(string): string, class-string<KsefException>, string}> */
    public static function brokenDocuments(): iterable
    {
        $replace = static fn (string $search, string $replacement): \Closure => static fn (string $xml): string => str_replace($search, $replacement, $xml);

        yield 'empty' => [static fn (): string => '', InvalidFa3InvoiceException::class, 'empty'];
        yield 'not XML' => [static fn (): string => '{"invoice": 1}', InvalidFa3InvoiceException::class, 'not well-formed XML'];
        yield 'truncated' => [static fn (string $xml): string => substr($xml, 0, 500), InvalidFa3InvoiceException::class, 'not well-formed XML'];
        yield 'DOCTYPE with an entity' => [
            static fn (string $xml): string => str_replace('<?xml version="1.0" encoding="UTF-8"?>', '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE Faktura [<!ENTITY x "x">]>', $xml),
            InvalidFa3InvoiceException::class,
            'DOCTYPE',
        ];
        yield 'FA(2) namespace' => [$replace('http://crd.gov.pl/wzor/2025/06/25/13775/', 'http://crd.gov.pl/wzor/2023/06/29/12648/'), UnsupportedInvoiceFormException::class, '12648'];
        yield 'another document' => [static fn (): string => '<?xml version="1.0"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>', UnsupportedInvoiceFormException::class, 'Invoice'];
        yield 'FA(2) form code' => [$replace('kodSystemowy="FA (3)"', 'kodSystemowy="FA (2)"'), UnsupportedInvoiceFormException::class, 'FA (2)'];
        yield 'another schema version' => [$replace('wersjaSchemy="1-0E"', 'wersjaSchemy="1-1E"'), UnsupportedInvoiceFormException::class, '1-1E'];
        yield 'no form code' => [$replace('<KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza>', ''), InvalidFa3InvoiceException::class, 'Naglowek/KodFormularza'];
        yield 'no invoice number' => [$replace('<P_2>FV/2026/11/0042</P_2>', ''), InvalidFa3InvoiceException::class, 'Fa/P_2'];
        yield 'empty invoice number' => [$replace('<P_2>FV/2026/11/0042</P_2>', '<P_2> </P_2>'), InvalidFa3InvoiceException::class, 'Fa/P_2'];
        yield 'no seller tax ID' => [$replace('<NIP>4882338469</NIP>', ''), InvalidFa3InvoiceException::class, 'Podmiot1/DaneIdentyfikacyjne/NIP'];
        yield 'no gross total' => [$replace('<P_15>1657.83</P_15>', ''), InvalidFa3InvoiceException::class, 'Fa/P_15'];
        yield 'repeated gross total' => [$replace('<P_15>1657.83</P_15>', '<P_15>1657.83</P_15><P_15>1.00</P_15>'), InvalidFa3InvoiceException::class, 'more than one Fa/P_15'];
        yield 'amount with three decimals' => [$replace('<P_13_1>1000.00</P_13_1>', '<P_13_1>1000.001</P_13_1>'), InvalidFa3InvoiceException::class, 'Fa/P_13_1'];
        yield 'amount with a decimal comma' => [$replace('<P_14_2>20.04</P_14_2>', '<P_14_2>20,04</P_14_2>'), InvalidFa3InvoiceException::class, 'Fa/P_14_2'];
        yield 'rate group without its VAT' => [$replace('<P_14_1>230.00</P_14_1>', ''), InvalidFa3InvoiceException::class, 'Fa/P_14_1'];
        yield 'issue date that does not exist' => [$replace('<P_1>2026-11-03</P_1>', '<P_1>2026-02-30</P_1>'), InvalidFa3InvoiceException::class, 'Fa/P_1'];
        yield 'issue date with a time' => [$replace('<P_1>2026-11-03</P_1>', '<P_1>2026-11-03T10:00:00</P_1>'), InvalidFa3InvoiceException::class, 'Fa/P_1'];
        yield 'unknown invoice kind' => [$replace('<RodzajFaktury>VAT</RodzajFaktury>', '<RodzajFaktury>PRO</RodzajFaktury>'), InvalidFa3InvoiceException::class, 'Fa/RodzajFaktury'];
        yield 'unknown currency' => [$replace('<KodWaluty>PLN</KodWaluty>', '<KodWaluty>XYZ</KodWaluty>'), InvalidFa3InvoiceException::class, 'Fa/KodWaluty'];
    }

    /**
     * @param \Closure(string): string     $break
     * @param class-string<KsefException> $exception
     */
    #[DataProvider('brokenDocuments')]
    public function test_it_rejects_a_document_it_cannot_read_as_fa3(\Closure $break, string $exception, string $message): void
    {
        $xml = (string) file_get_contents(self::FIXTURES.'Fa3/vat-several-rates.xml');
        $broken = $break($xml);
        self::assertNotSame($xml, $broken, 'The case must change the sample.');

        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $this->parser()->parse($broken);
    }

    private function parser(): Fa3InvoiceParser
    {
        return new Fa3InvoiceParser();
    }

    private function parse(string $file): PurchaseInvoiceDetails
    {
        return $this->parser()->parse((string) file_get_contents(self::FIXTURES.$file));
    }

    private function replace(string $file, string $search, string $replacement): string
    {
        $xml = (string) file_get_contents(self::FIXTURES.$file);
        self::assertStringContainsString($search, $xml);

        return str_replace($search, $replacement, $xml);
    }

    private function import(PurchaseInvoiceDetails $details): PurchaseInvoice
    {
        return PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'fixture-ksef-number', $details, 'PL', 'PLN', new \DateTimeImmutable('2026-11-13 10:00:00 UTC'));
    }
}
