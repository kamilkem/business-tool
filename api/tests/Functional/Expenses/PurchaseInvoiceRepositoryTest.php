<?php

declare(strict_types=1);

namespace App\Tests\Functional\Expenses;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Expenses\Domain\Model\PurchaseInvoiceVatLine;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Expenses\Infrastructure\Doctrine\Orm\Repository\PurchaseInvoiceDocumentRepository;
use App\Expenses\Infrastructure\Doctrine\Orm\Repository\PurchaseInvoiceRepository;
use App\Organization\Domain\Model\Organization;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Tests\Factory\OrganizationFactory;
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
