<?php

declare(strict_types=1);

namespace App\Tests\Functional\Expenses;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceItem;
use App\Expenses\Domain\Model\PurchaseInvoiceItemDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Expenses\Domain\Model\PurchaseInvoiceVatLine;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceFilter;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Expenses\Infrastructure\Doctrine\Orm\Repository\PurchaseInvoiceDocumentRepository;
use App\Expenses\Infrastructure\Doctrine\Orm\Repository\PurchaseInvoiceRepository;
use App\Organization\Domain\Model\MembershipRole;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Tests\Factory\OrganizationFactory;
use App\Tests\Fixtures\PurchaseInvoiceMother;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The purchase invoice adapters against PostgreSQL: the issue date as a DATE column, BIGINT and
 * negative amounts, VAT lines, the document stored with the invoice in one flush and loaded on its
 * own, the unique (organization, source, external id) and the TenantFilter on all three tables.
 */
#[CoversClass(PurchaseInvoiceRepository::class)]
#[CoversClass(PurchaseInvoiceDocumentRepository::class)]
final class PurchaseInvoiceRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private const string XML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Faktura><P_2>FV/1</P_2></Faktura>\n";

    private EntityManagerInterface $entityManager;
    private TenantContextInterface $tenantContext;
    private PurchaseInvoiceRepositoryInterface $invoices;
    private PurchaseInvoiceDocumentRepositoryInterface $documents;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->tenantContext = self::getContainer()->get(TenantContextInterface::class);
        $this->invoices = self::getContainer()->get(PurchaseInvoiceRepositoryInterface::class);
        $this->documents = self::getContainer()->get(PurchaseInvoiceDocumentRepositoryInterface::class);
    }

    public function test_it_stores_an_invoice_with_its_vat_lines_and_document_in_the_current_organization(): void
    {
        $organization = $this->switchToNewOrganization();
        $invoice = $this->import('K-1', currency: 'EUR', vatLines: [new PurchaseInvoiceVatAmount('8', 1_000, 80, 340), new PurchaseInvoiceVatAmount('23', 9_000_000_000_000, 2_070_000_000_000, 8_800_000_000_000)]);
        $this->invoices->add($invoice, PurchaseInvoiceDocument::of($invoice, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')));
        $this->entityManager->clear();

        $found = $this->invoices->findById($invoice->getId());
        self::assertNotNull($found);
        self::assertSame(PurchaseInvoiceSource::Ksef, $found->getSource());
        self::assertSame('K-1', $found->getExternalId());
        self::assertSame('2026-11-10', $found->getIssueDate()->toString());
        self::assertSame(PurchaseInvoiceKind::Regular, $found->getKind());
        self::assertSame(['EUR', 'PLN'], [$found->getCurrency(), $found->getTaxCurrency()]);
        self::assertSame([30_500, 5_400, 35_900], [$found->getNetTotal(), $found->getVatTotal(), $found->getGrossTotal()]);
        self::assertEquals(
            [new PurchaseInvoiceVatAmount('23', 9_000_000_000_000, 2_070_000_000_000, 8_800_000_000_000), new PurchaseInvoiceVatAmount('8', 1_000, 80, 340)],
            $found->getVatLines(),
        );
        self::assertEquals(new \DateTimeImmutable('2026-11-12 21:00:00'), $found->getCreatedAt());
        self::assertTrue($found->getOrganization()?->getId()->equals($organization->getId()));
        self::assertSame($invoice->getId()->toRfc4122(), $this->invoices->findByExternalId(PurchaseInvoiceSource::Ksef, 'K-1')?->getId()->toRfc4122());
        self::assertNull($this->invoices->findByExternalId(PurchaseInvoiceSource::Manual, 'K-1'));
        self::assertNull($this->invoices->findByExternalId(PurchaseInvoiceSource::Ksef, 'K-2'));

        $document = $this->documents->findByInvoiceId($invoice->getId());
        self::assertNotNull($document);
        self::assertSame(self::XML, $document->getContent());
        self::assertSame('FA (3)', $document->getFormat());
        self::assertSame(hash('sha256', self::XML), $document->getSha256());
        self::assertTrue($document->getPurchaseInvoice()->getId()->equals($invoice->getId()));
        self::assertTrue($document->getOrganization()?->getId()->equals($organization->getId()));
    }

    public function test_it_keeps_negative_amounts_of_a_correction(): void
    {
        $this->switchToNewOrganization();
        $invoice = $this->import('K-2', kind: PurchaseInvoiceKind::Correction, totals: [-1_000, -230, -1_230], vatLines: [new PurchaseInvoiceVatAmount('23', -1_000, -230)], correctedExternalId: 'K-1');
        $this->invoices->add($invoice);
        $this->entityManager->clear();

        $found = $this->invoices->findById($invoice->getId());
        self::assertNotNull($found);
        self::assertSame([-1_000, -230, -1_230], [$found->getNetTotal(), $found->getVatTotal(), $found->getGrossTotal()]);
        self::assertEquals([new PurchaseInvoiceVatAmount('23', -1_000, -230)], $found->getVatLines());
        self::assertSame('K-1', $found->getCorrectedExternalId());
        self::assertNull($this->documents->findByInvoiceId($invoice->getId()));
    }

    public function test_it_stores_the_items_with_their_decimals_exactly(): void
    {
        $organization = $this->switchToNewOrganization();
        $invoice = PurchaseInvoiceMother::imported(externalId: 'K-1', items: [
            new PurchaseInvoiceItemDetails(position: 2, name: 'Woda', quantity: '-9999999999999999.123456', unitNetPrice: '99999999999999.12345678', discount: '0.00000001', netAmount: -9_000_000_000_000, vatRate: '8'),
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', sellerCode: 'MIE-0042', gtin: '5901234123457', unit: 'kg', quantity: '0.7', unitGrossPrice: '12.34567891', grossAmount: 864, vatAmount: 162, vatRate: '23', beforeCorrection: true),
        ]);
        $this->invoices->add($invoice);
        $this->entityManager->clear();

        $found = $this->invoices->findById($invoice->getId()) ?? self::fail('Stored invoice expected.');
        self::assertEquals([
            new PurchaseInvoiceItemDetails(position: 1, name: 'Polędwica', sellerCode: 'MIE-0042', gtin: '5901234123457', unit: 'kg', quantity: '0.7', unitGrossPrice: '12.34567891', grossAmount: 864, vatAmount: 162, vatRate: '23', beforeCorrection: true),
            new PurchaseInvoiceItemDetails(position: 2, name: 'Woda', quantity: '-9999999999999999.123456', unitNetPrice: '99999999999999.12345678', discount: '0.00000001', netAmount: -9_000_000_000_000, vatRate: '8'),
        ], $found->getItems());

        $stored = $this->entityManager->getConnection()->fetchAllAssociative('SELECT quantity, unit_gross_price, organization_id FROM purchase_invoice_item WHERE purchase_invoice_id = ? ORDER BY ordinal', [$invoice->getId()->toRfc4122()]);
        self::assertSame(['-9999999999999999.123456', '0.700000'], array_column($stored, 'quantity'));
        self::assertSame('12.34567891', $stored[1]['unit_gross_price']);
        self::assertSame([$organization->getId()->toRfc4122(), $organization->getId()->toRfc4122()], array_column($stored, 'organization_id'));
    }

    public function test_it_removes_the_items_with_their_invoice_and_hides_another_organizations(): void
    {
        $this->switchToNewOrganization();
        $foreign = $this->add(PurchaseInvoiceMother::imported(items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));
        $this->switchToNewOrganization();
        $this->add(PurchaseInvoiceMother::imported(items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Wino'), new PurchaseInvoiceItemDetails(position: 2, name: 'Woda')]));
        $this->entityManager->clear();

        self::assertSame(2, (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(IDENTITY(i.purchaseInvoice)) FROM %s i', PurchaseInvoiceItem::class))->getSingleScalarResult());

        $this->entityManager->getConnection()->executeStatement('DELETE FROM purchase_invoice WHERE id = ?', [$foreign->getId()->toRfc4122()]);
        self::assertSame(2, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM purchase_invoice_item'));
    }

    public function test_it_rejects_an_external_id_already_stored_in_the_organization(): void
    {
        $this->switchToNewOrganization();
        $this->invoices->add($this->import('K-1'));

        $this->expectException(PurchaseInvoiceAlreadyImportedException::class);

        // A concurrent import that did not see the first one: only the constraint can stop it.
        $this->invoices->add($this->import('K-1'));
    }

    public function test_it_stores_neither_the_invoice_nor_its_document_when_the_external_id_is_taken(): void
    {
        $this->switchToNewOrganization();
        $this->invoices->add($this->import('K-1'));
        $duplicate = $this->import('K-1');

        try {
            $this->invoices->add($duplicate, PurchaseInvoiceDocument::of($duplicate, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')));
            self::fail('The duplicate was stored.');
        } catch (PurchaseInvoiceAlreadyImportedException) {
        }

        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM purchase_invoice_document'));
    }

    public function test_it_allows_the_same_external_id_in_another_organization(): void
    {
        $this->switchToNewOrganization();
        $this->invoices->add($this->import('K-1'));
        $this->entityManager->clear();

        $this->switchToNewOrganization();
        $this->invoices->add($this->import('K-1'));

        self::assertNotNull($this->invoices->findByExternalId(PurchaseInvoiceSource::Ksef, 'K-1'));
    }

    public function test_it_rejects_a_document_of_another_invoice(): void
    {
        $this->switchToNewOrganization();
        $other = $this->import('K-2');

        $this->expectException(\InvalidArgumentException::class);

        $this->invoices->add($this->import('K-1'), PurchaseInvoiceDocument::of($other, self::XML, 'FA (3)', new \DateTimeImmutable()));
    }

    public function test_it_saves_changes_to_a_managed_invoice(): void
    {
        $this->switchToNewOrganization();
        $invoice = $this->import('K-1');
        $this->invoices->add($invoice);

        $this->invoices->save($invoice);
        $this->entityManager->clear();

        self::assertNotNull($this->invoices->findById($invoice->getId()));
    }

    public function test_it_stores_a_manual_invoice_without_a_number_or_seller_tax_id(): void
    {
        $this->switchToNewOrganization();
        $invoice = $this->add(PurchaseInvoiceMother::manual(invoiceNumber: null, sellerTaxId: null));
        $this->entityManager->clear();

        $found = $this->invoices->findById($invoice->getId()) ?? self::fail('Stored invoice expected.');
        self::assertSame(PurchaseInvoiceSource::Manual, $found->getSource());
        self::assertNull($found->getExternalId());
        self::assertNull($found->getInvoiceNumber());
        self::assertNull($found->getSellerTaxId());
    }

    public function test_it_saves_a_manual_invoices_vat_lines_changed_in_place_removed_and_added(): void
    {
        $organization = $this->switchToNewOrganization();
        $invoice = $this->add(PurchaseInvoiceMother::manual(vatLines: [new PurchaseInvoiceVatAmount('8', 5_000, 400), new PurchaseInvoiceVatAmount('23', 1_000, 230)]));
        $this->entityManager->clear();
        // As in a request: the selected organization is managed, so the new line can refer to it.
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? self::fail('Organization expected.'));
        $managed = $this->invoices->findById($invoice->getId()) ?? self::fail('Stored invoice expected.');

        // The line at 8% stays (same primary key), 23% goes, 5% comes: one flush.
        $managed->change(new PurchaseInvoiceDetails(null, null, 'Targ Rybny', BusinessDate::fromString('2026-11-14'), PurchaseInvoiceKind::Regular, 'PLN', 6_000, 530, 6_530, [
            new PurchaseInvoiceVatAmount('8', 4_000, 320),
            new PurchaseInvoiceVatAmount('5', 2_000, 100),
        ]), 'PL', Uuid::v7(), MembershipRole::Owner);
        $this->invoices->save($managed);
        $this->entityManager->clear();

        $found = $this->invoices->findById($invoice->getId()) ?? self::fail('Stored invoice expected.');
        self::assertEquals([new PurchaseInvoiceVatAmount('8', 4_000, 320), new PurchaseInvoiceVatAmount('5', 2_000, 100)], $found->getVatLines());
        self::assertSame(6_530, $found->getGrossTotal());
    }

    public function test_it_removes_an_invoice_with_its_vat_lines(): void
    {
        $this->switchToNewOrganization();
        $removed = $this->add(PurchaseInvoiceMother::manual());
        $kept = $this->add(PurchaseInvoiceMother::manual());
        $this->entityManager->clear();

        $this->invoices->remove($this->invoices->findById($removed->getId()) ?? self::fail('Stored invoice expected.'));
        $this->entityManager->clear();

        self::assertNull($this->invoices->findById($removed->getId()));
        self::assertNotNull($this->invoices->findById($kept->getId()));
        self::assertSame(1, (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(IDENTITY(l.purchaseInvoice)) FROM %s l', PurchaseInvoiceVatLine::class))->getSingleScalarResult());
    }

    public function test_it_does_not_show_another_organizations_invoices_lines_or_documents(): void
    {
        $this->switchToNewOrganization();
        $foreign = $this->import('K-1');
        $this->invoices->add($foreign, PurchaseInvoiceDocument::of($foreign, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')));
        $this->entityManager->clear();

        $this->switchToNewOrganization();

        self::assertNull($this->invoices->findById($foreign->getId()));
        self::assertNull($this->invoices->findByExternalId(PurchaseInvoiceSource::Ksef, 'K-1'));
        self::assertNull($this->documents->findByInvoiceId($foreign->getId()));
        self::assertSame(0, (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(IDENTITY(l.purchaseInvoice)) FROM %s l', PurchaseInvoiceVatLine::class))->getSingleScalarResult());
        self::assertSame(0, (int) $this->entityManager->createQuery(\sprintf('SELECT COUNT(IDENTITY(d.purchaseInvoice)) FROM %s d', PurchaseInvoiceDocument::class))->getSingleScalarResult());
    }

    public function test_it_finds_the_periods_invoices_newest_first_with_their_lines(): void
    {
        $this->switchToNewOrganization();
        $first = $this->add(PurchaseInvoiceMother::imported(issueDate: '2026-11-01', sellerName: 'Zakład'));
        $lastB = $this->add(PurchaseInvoiceMother::imported(issueDate: '2026-11-30', sellerName: 'Browar', invoiceNumber: 'B/2'));
        $lastA = $this->add(PurchaseInvoiceMother::imported(issueDate: '2026-11-30', sellerName: 'Browar', invoiceNumber: 'B/1'));
        $middle = $this->add(PurchaseInvoiceMother::imported(issueDate: '2026-11-15', sellerName: 'Apteka'));
        $this->add(PurchaseInvoiceMother::imported(issueDate: '2026-10-31'));
        $this->add(PurchaseInvoiceMother::imported(issueDate: '2026-12-01'));
        $this->entityManager->clear();

        $found = $this->invoices->findByFilter(new PurchaseInvoiceFilter(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30')));

        self::assertSame(self::idsOf([$lastA, $lastB, $middle, $first]), self::idsOf($found));
        self::assertEquals([new PurchaseInvoiceVatAmount('23', 20_000, 4_600)], $found[0]->getVatLines());
    }

    public function test_it_finds_sellers_by_a_part_of_the_name_or_tax_id(): void
    {
        $this->switchToNewOrganization();
        $beer = $this->add(PurchaseInvoiceMother::imported(sellerName: 'Browar Kormoran', sellerTaxId: '7393010870'));
        $percent = $this->add(PurchaseInvoiceMother::imported(sellerName: 'Rabat 100% s.c.', sellerTaxId: 'PL1132853869'));
        $this->add(PurchaseInvoiceMother::imported(sellerName: 'ZAKŁAD MIĘSNY', sellerTaxId: '5265877635'));
        $this->entityManager->clear();

        foreach ([
            'kormoran' => [$beer],
            '739-301 08' => [$beer],
            'pl113' => [$percent],
            '%' => [$percent],
            '100%' => [$percent],
            '_' => [],
            'browar zakład' => [],
        ] as $seller => $expected) {
            $found = $this->invoices->findByFilter(new PurchaseInvoiceFilter(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'), seller: (string) $seller));
            self::assertSame(self::idsOf($expected), self::idsOf($found), (string) $seller);
        }
    }

    public function test_it_finds_the_formats_of_the_documents_without_their_content(): void
    {
        $this->switchToNewOrganization();
        $with = PurchaseInvoiceMother::imported();
        $this->invoices->add($with, PurchaseInvoiceDocument::of($with, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')));
        $without = $this->add(PurchaseInvoiceMother::imported());
        $this->entityManager->clear();

        self::assertSame(
            [$with->getId()->toRfc4122() => 'FA (3)'],
            $this->documents->findFormatsByInvoiceIds([$with->getId(), $without->getId(), Uuid::v7()]),
        );
        self::assertSame([], $this->documents->findFormatsByInvoiceIds([]));
    }

    public function test_it_finds_no_invoices_or_formats_of_another_organization(): void
    {
        $this->switchToNewOrganization();
        $foreign = PurchaseInvoiceMother::imported();
        $this->invoices->add($foreign, PurchaseInvoiceDocument::of($foreign, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')));
        $this->entityManager->clear();

        $this->switchToNewOrganization();

        self::assertSame([], $this->invoices->findByFilter(new PurchaseInvoiceFilter(BusinessPeriod::fromStrings('2026-11-01', '2026-11-30'))));
        self::assertSame([], $this->documents->findFormatsByInvoiceIds([$foreign->getId()]));
    }

    public function test_it_tells_which_external_ids_of_a_source_the_organization_stores(): void
    {
        $this->switchToNewOrganization();
        $this->add($this->import('K-OTHER-ORGANIZATION'));
        $this->entityManager->clear();
        $this->switchToNewOrganization();
        $this->add($this->import('K-1'));
        $this->add($this->import('K-2'));

        $stored = $this->invoices->findStoredExternalIds(PurchaseInvoiceSource::Ksef, ['K-2', 'K-3', 'K-1', 'K-OTHER-ORGANIZATION', 'K-1']);
        sort($stored);

        self::assertSame(['K-1', 'K-2'], $stored);
        self::assertSame([], $this->invoices->findStoredExternalIds(PurchaseInvoiceSource::Manual, ['K-1']));
        self::assertSame([], $this->invoices->findStoredExternalIds(PurchaseInvoiceSource::Ksef, []));
    }

    public function test_it_lists_the_imported_invoices_with_a_document_and_no_items_by_id(): void
    {
        $this->switchToNewOrganization();
        $foreign = $this->withDocument($this->import('K-OTHER-ORGANIZATION'));
        $this->entityManager->clear();
        $organization = $this->switchToNewOrganization();
        $a = $this->withDocument($this->import('K-1'));
        $b = $this->withDocument($this->import('K-2'));
        $c = $this->withDocument($this->import('K-3'));
        $this->withDocument(PurchaseInvoiceMother::imported(externalId: 'K-WITH-ITEMS', items: [new PurchaseInvoiceItemDetails(position: 1, name: 'Piwo')]));
        $this->add($this->import('K-WITHOUT-DOCUMENT'));
        $this->add(PurchaseInvoiceMother::manual());
        $this->entityManager->clear();
        $ids = self::idsOf([$a, $b, $c]);
        sort($ids);

        $first = $this->invoices->findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource::Ksef, null, 2);
        $rest = $this->invoices->findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource::Ksef, $first[1], 2);

        self::assertSame(\array_slice($ids, 0, 2), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $first));
        self::assertSame([$ids[2]], array_map(static fn (Uuid $id): string => $id->toRfc4122(), $rest));
        self::assertNotContains($foreign->getId()->toRfc4122(), $ids);
        self::assertSame([], $this->invoices->findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource::Manual, null, 10));

        // Once its items are recorded, an invoice is no longer listed. The job bus switches to a managed organization.
        $this->tenantContext->switchTo($this->entityManager->find(Organization::class, $organization->getId()) ?? self::fail('Organization expected.'));
        $invoice = $this->invoices->findById(Uuid::fromString($ids[0])) ?? self::fail('Invoice expected.');
        self::assertTrue($invoice->recordItems([new PurchaseInvoiceItemDetails(position: 1, name: 'Wino')], 'PL'));
        $this->invoices->save($invoice);
        $this->entityManager->clear();

        self::assertSame(\array_slice($ids, 1), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $this->invoices->findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource::Ksef, null, 10)));
        self::assertEquals([new PurchaseInvoiceItemDetails(position: 1, name: 'Wino')], $this->invoices->findById(Uuid::fromString($ids[0]))?->getItems());
    }

    private function withDocument(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $this->invoices->add($invoice, PurchaseInvoiceDocument::of($invoice, self::XML, 'FA (3)', new \DateTimeImmutable('2026-11-12 21:00:05')));

        return $invoice;
    }

    private function add(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $this->invoices->add($invoice);

        return $invoice;
    }

    /**
     * @param list<PurchaseInvoice> $invoices
     *
     * @return list<string>
     */
    private static function idsOf(array $invoices): array
    {
        return array_map(static fn (PurchaseInvoice $invoice): string => $invoice->getId()->toRfc4122(), $invoices);
    }

    private function switchToNewOrganization(): Organization
    {
        $organization = OrganizationFactory::createOne();
        $this->tenantContext->switchTo($organization);

        return $organization;
    }

    /**
     * @param array{int, int, int}           $totals net, VAT, gross
     * @param list<PurchaseInvoiceVatAmount> $vatLines
     */
    private function import(
        string $externalId,
        PurchaseInvoiceKind $kind = PurchaseInvoiceKind::Regular,
        string $currency = 'PLN',
        array $totals = [30_500, 5_400, 35_900],
        array $vatLines = [new PurchaseInvoiceVatAmount('23', 20_000, 4_600)],
        ?string $correctedExternalId = null,
    ): PurchaseInvoice {
        $details = new PurchaseInvoiceDetails('FV/1/2026', '5265877635', 'Hurtownia', BusinessDate::fromString('2026-11-10'), $kind, $currency, $totals[0], $totals[1], $totals[2], $vatLines, $correctedExternalId);

        return PurchaseInvoice::import(Uuid::v7(), PurchaseInvoiceSource::Ksef, $externalId, $details, 'PL', 'PLN', new \DateTimeImmutable('2026-11-12 21:00:00'));
    }
}
