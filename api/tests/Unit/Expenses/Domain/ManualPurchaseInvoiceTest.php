<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Domain;

use App\Expenses\Domain\Exception\ExpensesManagementForbiddenException;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceReadOnlyException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Expenses\Domain\Model\PurchaseInvoiceVatLine;
use App\Organization\Domain\Model\MembershipRole;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Invoices entered by hand (#54): entering, changing and deleting them, and why imported ones refuse it. */
#[CoversClass(PurchaseInvoice::class)]
#[CoversClass(PurchaseInvoiceVatLine::class)]
#[CoversClass(PurchaseInvoiceReadOnlyException::class)]
final class ManualPurchaseInvoiceTest extends TestCase
{
    private const string NOW = '2026-11-20 10:00:00';

    /** @return iterable<string, array{MembershipRole}> */
    public static function managers(): iterable
    {
        yield 'owner' => [MembershipRole::Owner];
        yield 'manager' => [MembershipRole::Manager];
    }

    #[DataProvider('managers')]
    public function test_it_enters_an_invoice_by_hand_with_the_manual_source_and_no_external_id(MembershipRole $role): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable(self::NOW);

        $invoice = PurchaseInvoice::enter($id, self::details(
            invoiceNumber: ' PAR/1 ',
            sellerTaxId: ' 5265877635 ',
            sellerName: ' Targ Rybny ',
            currency: 'pln',
            vatLines: [new PurchaseInvoiceVatAmount('8', 5_000, 400), new PurchaseInvoiceVatAmount('23', 1_000, 230)],
        ), 'PL', 'pln', Uuid::v7(), $role, $now);

        self::assertSame($id, $invoice->getId());
        self::assertSame(PurchaseInvoiceSource::Manual, $invoice->getSource());
        self::assertNull($invoice->getExternalId());
        self::assertSame('PAR/1', $invoice->getInvoiceNumber());
        self::assertSame('5265877635', $invoice->getSellerTaxId());
        self::assertSame('Targ Rybny', $invoice->getSellerName());
        self::assertSame('PLN', $invoice->getCurrency());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 1_000, 230), new PurchaseInvoiceVatAmount('8', 5_000, 400)], $invoice->getVatLines());
        self::assertSame($now, $invoice->getCreatedAt());
    }

    public function test_it_enters_an_invoice_without_a_number_or_seller_tax_id(): void
    {
        foreach ([null, '', '   '] as $missing) {
            $invoice = PurchaseInvoice::enter(Uuid::v7(), self::details(invoiceNumber: $missing, sellerTaxId: $missing), 'PL', 'PLN', Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));

            self::assertNull($invoice->getInvoiceNumber());
            self::assertNull($invoice->getSellerTaxId());
        }
    }

    public function test_it_still_requires_an_imported_invoice_to_have_a_number_and_seller_tax_id(): void
    {
        $refused = 0;
        foreach ([self::details(invoiceNumber: null), self::details(sellerTaxId: null)] as $details) {
            try {
                PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, 'K-1', $details, 'PL', 'PLN', new \DateTimeImmutable(self::NOW));
            } catch (InvalidPurchaseInvoiceException) {
                ++$refused;
            }
        }

        self::assertSame(2, $refused);
    }

    public function test_it_refuses_entering_to_an_employee(): void
    {
        $this->expectException(ExpensesManagementForbiddenException::class);

        PurchaseInvoice::enter(Uuid::v7(), self::details(), 'PL', 'PLN', Uuid::v7(), MembershipRole::Employee, new \DateTimeImmutable(self::NOW));
    }

    /** @return iterable<string, array{PurchaseInvoiceDetails}> */
    public static function invalidDetails(): iterable
    {
        yield 'blank seller name' => [self::details(sellerName: ' ')];
        yield 'invoice number too long' => [self::details(invoiceNumber: str_repeat('n', 257))];
        yield 'seller tax ID too long' => [self::details(sellerTaxId: str_repeat('1', 33))];
        yield 'invalid currency' => [self::details(currency: 'zł')];
        yield 'rate of another country' => [self::details(vatLines: [new PurchaseInvoiceVatAmount('19', 100, 19)])];
        yield 'rate twice' => [self::details(vatLines: [new PurchaseInvoiceVatAmount('8', 100, 8), new PurchaseInvoiceVatAmount('8', 50, 4)])];
        yield 'VAT at exempt rate' => [self::details(vatLines: [new PurchaseInvoiceVatAmount('zw', 100, 1)])];
        yield 'foreign currency without VAT in PLN' => [self::details(currency: 'EUR', vatLines: [new PurchaseInvoiceVatAmount('23', 100, 23)])];
        yield 'own currency with VAT in PLN' => [self::details(vatLines: [new PurchaseInvoiceVatAmount('23', 100, 23, 23)])];
        yield 'corrected invoice id' => [self::details(kind: PurchaseInvoiceKind::Correction, correctedExternalId: 'K-1')];
    }

    #[DataProvider('invalidDetails')]
    public function test_it_rejects_invalid_details_when_entering(PurchaseInvoiceDetails $details): void
    {
        $this->expectException(InvalidPurchaseInvoiceException::class);

        PurchaseInvoice::enter(Uuid::v7(), $details, 'PL', 'PLN', Uuid::v7(), MembershipRole::Owner, new \DateTimeImmutable(self::NOW));
    }

    #[DataProvider('invalidDetails')]
    public function test_it_rejects_invalid_details_when_changing(PurchaseInvoiceDetails $details): void
    {
        $invoice = PurchaseInvoiceMother::manual();
        $before = $invoice->getVatLines();

        try {
            $invoice->change($details, 'PL', Uuid::v7(), MembershipRole::Owner);
            self::fail('Invalid expected.');
        } catch (InvalidPurchaseInvoiceException) {
        }

        self::assertSame('Targ Rybny', $invoice->getSellerName());
        self::assertEquals($before, $invoice->getVatLines());
    }

    #[DataProvider('managers')]
    public function test_it_changes_everything_a_manual_invoice_says_but_its_tax_currency(MembershipRole $role): void
    {
        $invoice = PurchaseInvoiceMother::manual(vatLines: [new PurchaseInvoiceVatAmount('8', 5_000, 400), new PurchaseInvoiceVatAmount('23', 1_000, 230)]);
        $createdAt = $invoice->getCreatedAt();

        $invoice->change(new PurchaseInvoiceDetails(
            ' FV/9/2026 ',
            'DE123456789',
            ' Weingut Müller ',
            BusinessDate::fromString('2026-11-03'),
            PurchaseInvoiceKind::Simplified,
            'eur',
            10_000,
            1_900,
            11_900,
            // 8 changes in place, 23 goes, zw comes.
            [new PurchaseInvoiceVatAmount('8', 9_000, 720, 3_100), new PurchaseInvoiceVatAmount('zw', 1_000, 0, 0)],
        ), 'PL', Uuid::v7(), $role);

        self::assertSame('FV/9/2026', $invoice->getInvoiceNumber());
        self::assertSame('DE123456789', $invoice->getSellerTaxId());
        self::assertSame('Weingut Müller', $invoice->getSellerName());
        self::assertSame('2026-11-03', $invoice->getIssueDate()->toString());
        self::assertSame(PurchaseInvoiceKind::Simplified, $invoice->getKind());
        self::assertSame('EUR', $invoice->getCurrency());
        self::assertSame('PLN', $invoice->getTaxCurrency());
        self::assertSame([10_000, 1_900, 11_900], [$invoice->getNetTotal(), $invoice->getVatTotal(), $invoice->getGrossTotal()]);
        self::assertEquals([new PurchaseInvoiceVatAmount('8', 9_000, 720, 3_100), new PurchaseInvoiceVatAmount('zw', 1_000, 0, 0)], $invoice->getVatLines());
        self::assertSame(PurchaseInvoiceSource::Manual, $invoice->getSource());
        self::assertSame($createdAt, $invoice->getCreatedAt());
    }

    public function test_it_removes_the_number_seller_tax_id_and_vat_lines_of_a_manual_invoice(): void
    {
        $invoice = PurchaseInvoiceMother::manual(sellerTaxId: '5265877635');

        $invoice->change(self::details(invoiceNumber: '', sellerTaxId: null, vatLines: []), 'PL', Uuid::v7(), MembershipRole::Manager);

        self::assertNull($invoice->getInvoiceNumber());
        self::assertNull($invoice->getSellerTaxId());
        self::assertSame([], $invoice->getVatLines());
    }

    public function test_it_refuses_changing_to_an_employee(): void
    {
        $this->expectException(ExpensesManagementForbiddenException::class);

        PurchaseInvoiceMother::manual()->change(self::details(), 'PL', Uuid::v7(), MembershipRole::Employee);
    }

    public function test_it_accepts_an_imported_invoices_own_details_unchanged(): void
    {
        $invoice = PurchaseInvoiceMother::imported(
            kind: PurchaseInvoiceKind::Correction,
            correctedExternalId: 'K-0',
            vatLines: [new PurchaseInvoiceVatAmount('8', 10_000, 800), new PurchaseInvoiceVatAmount('23', 10_000, 2_300)],
            totals: [20_000, 3_100, 23_100],
        );

        // Lines in another order, texts padded: still what the invoice says.
        $invoice->change(new PurchaseInvoiceDetails(
            ' FV/1/2026',
            '5265877635 ',
            'Hurtownia Napojów',
            BusinessDate::fromString('2026-11-10'),
            PurchaseInvoiceKind::Correction,
            'pln',
            20_000,
            3_100,
            23_100,
            [new PurchaseInvoiceVatAmount('23', 10_000, 2_300), new PurchaseInvoiceVatAmount('8', 10_000, 800)],
        ), 'PL', Uuid::v7(), MembershipRole::Owner);

        self::assertSame('FV/1/2026', $invoice->getInvoiceNumber());
        self::assertSame('K-0', $invoice->getCorrectedExternalId());
    }

    /** @return iterable<string, array{PurchaseInvoiceDetails}> */
    public static function otherDetailsOfTheImportedInvoice(): iterable
    {
        $same = ['FV/1/2026', '5265877635', 'Hurtownia Napojów', '2026-11-10', PurchaseInvoiceKind::Regular, 'PLN', 20_000, 4_600, 24_600, [new PurchaseInvoiceVatAmount('23', 20_000, 4_600)]];
        $with = static function (int $index, mixed $value) use ($same): PurchaseInvoiceDetails {
            $fields = $same;
            $fields[$index] = $value;

            return new PurchaseInvoiceDetails($fields[0], $fields[1], $fields[2], BusinessDate::fromString($fields[3]), $fields[4], $fields[5], $fields[6], $fields[7], $fields[8], $fields[9]);
        };

        yield 'invoice number' => [$with(0, 'FV/2/2026')];
        yield 'no invoice number' => [$with(0, null)];
        yield 'seller tax ID' => [$with(1, '1132853869')];
        yield 'seller name' => [$with(2, 'Browar')];
        yield 'issue date' => [$with(3, '2026-11-11')];
        yield 'kind' => [$with(4, PurchaseInvoiceKind::Simplified)];
        yield 'currency' => [$with(5, 'EUR')];
        yield 'net total' => [$with(6, 1)];
        yield 'VAT total' => [$with(7, 1)];
        yield 'gross total' => [$with(8, 1)];
        yield 'VAT line amount' => [$with(9, [new PurchaseInvoiceVatAmount('23', 20_000, 4_601)])];
        yield 'VAT line added' => [$with(9, [new PurchaseInvoiceVatAmount('23', 20_000, 4_600), new PurchaseInvoiceVatAmount('zw', 0, 0)])];
        yield 'VAT line removed' => [$with(9, [])];
        yield 'VAT line twice' => [$with(9, [new PurchaseInvoiceVatAmount('23', 20_000, 4_600), new PurchaseInvoiceVatAmount('23', 20_000, 4_600)])];
    }

    #[DataProvider('otherDetailsOfTheImportedInvoice')]
    public function test_it_refuses_other_details_for_an_imported_invoice(PurchaseInvoiceDetails $details): void
    {
        $invoice = PurchaseInvoiceMother::imported();

        try {
            $invoice->change($details, 'PL', Uuid::v7(), MembershipRole::Owner);
            self::fail('Read-only expected.');
        } catch (PurchaseInvoiceReadOnlyException) {
        }

        self::assertSame('FV/1/2026', $invoice->getInvoiceNumber());
        self::assertSame(24_600, $invoice->getGrossTotal());
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 20_000, 4_600)], $invoice->getVatLines());
    }

    public function test_it_lets_an_owner_or_manager_delete_only_a_manual_invoice(): void
    {
        PurchaseInvoiceMother::manual()->assertCanBeDeleted(Uuid::v7(), MembershipRole::Owner);
        PurchaseInvoiceMother::manual()->assertCanBeDeleted(Uuid::v7(), MembershipRole::Manager);

        $refused = [];
        foreach ([[PurchaseInvoiceMother::imported(), MembershipRole::Owner], [PurchaseInvoiceMother::manual(), MembershipRole::Employee]] as [$invoice, $role]) {
            try {
                $invoice->assertCanBeDeleted(Uuid::v7(), $role);
            } catch (PurchaseInvoiceReadOnlyException|ExpensesManagementForbiddenException $e) {
                $refused[] = $e::class;
            }
        }

        self::assertSame([PurchaseInvoiceReadOnlyException::class, ExpensesManagementForbiddenException::class], $refused);
    }

    /** @param list<PurchaseInvoiceVatAmount> $vatLines */
    private static function details(
        ?string $invoiceNumber = 'PAR/1',
        ?string $sellerTaxId = null,
        string $sellerName = 'Targ Rybny',
        PurchaseInvoiceKind $kind = PurchaseInvoiceKind::Regular,
        string $currency = 'PLN',
        array $vatLines = [new PurchaseInvoiceVatAmount('8', 5_000, 400)],
        ?string $correctedExternalId = null,
    ): PurchaseInvoiceDetails {
        return new PurchaseInvoiceDetails($invoiceNumber, $sellerTaxId, $sellerName, BusinessDate::fromString('2026-11-14'), $kind, $currency, 5_000, 400, 5_400, $vatLines, $correctedExternalId);
    }
}
