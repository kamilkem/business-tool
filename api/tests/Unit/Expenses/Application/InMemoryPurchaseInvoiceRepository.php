<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use App\Expenses\Domain\Repository\PurchaseInvoiceFilter;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/** Fake implementing both ports for one organization: no container, no database. */
final class InMemoryPurchaseInvoiceRepository implements PurchaseInvoiceRepositoryInterface, PurchaseInvoiceDocumentRepositoryInterface
{
    /** @var array<string, PurchaseInvoice> */
    private array $byId = [];

    /** @var array<string, PurchaseInvoiceDocument> */
    private array $documents = [];

    public int $saveCallCount = 0;

    public function findById(Uuid $id): ?PurchaseInvoice
    {
        return $this->byId[$id->toRfc4122()] ?? null;
    }

    public function findByExternalId(PurchaseInvoiceSource $source, string $externalId): ?PurchaseInvoice
    {
        foreach ($this->byId as $invoice) {
            if ($invoice->getSource() === $source && $invoice->getExternalId() === $externalId) {
                return $invoice;
            }
        }

        return null;
    }

    public function findStoredExternalIds(PurchaseInvoiceSource $source, array $externalIds): array
    {
        $stored = [];
        foreach ($this->byId as $invoice) {
            $externalId = $invoice->getExternalId();
            if ($invoice->getSource() === $source && null !== $externalId && \in_array($externalId, $externalIds, true)) {
                $stored[] = $externalId;
            }
        }

        return $stored;
    }

    public function findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource $source, ?Uuid $after, int $limit): array
    {
        $ids = [];
        foreach ($this->byId as $id => $invoice) {
            if ($invoice->getSource() === $source && isset($this->documents[$id]) && [] === $invoice->getItems()
                && (null === $after || strcmp($id, $after->toRfc4122()) > 0)) {
                $ids[] = $id;
            }
        }
        // Lowercase RFC 4122 strings sort as PostgreSQL sorts UUIDs.
        sort($ids);

        return array_map(static fn (string $id): Uuid => Uuid::fromString($id), \array_slice($ids, 0, $limit));
    }

    public function findWithItemsAfter(?Uuid $after, int $limit): array
    {
        $ids = [];
        foreach ($this->byId as $id => $invoice) {
            if ([] !== $invoice->getItems() && (null === $after || strcmp($id, $after->toRfc4122()) > 0)) {
                $ids[] = $id;
            }
        }
        // Lowercase RFC 4122 strings sort as PostgreSQL sorts UUIDs.
        sort($ids);

        return array_map(fn (string $id): PurchaseInvoice => $this->byId[$id], \array_slice($ids, 0, $limit));
    }

    public function findOrganizationIdsWithItems(): array
    {
        throw new \LogicException('Spans organizations: tested against the database.');
    }

    public function findByFilter(PurchaseInvoiceFilter $filter): array
    {
        $invoices = array_values(array_filter($this->byId, static function (PurchaseInvoice $invoice) use ($filter): bool {
            $taxId = $filter->sellerTaxId();

            return $filter->period->contains($invoice->getIssueDate())
                && (null === $filter->seller
                    || str_contains(mb_strtolower($invoice->getSellerName()), $filter->seller)
                    || (null !== $taxId && str_contains(mb_strtolower($invoice->getSellerTaxId() ?? ''), $taxId)));
        }));
        // PostgreSQL sorts a missing invoice number (NULL) last in ascending order.
        usort($invoices, static fn (PurchaseInvoice $a, PurchaseInvoice $b): int => [$b->getIssueDate()->toString(), $a->getSellerName(), null === $a->getInvoiceNumber(), (string) $a->getInvoiceNumber(), $a->getId()->toRfc4122()]
            <=> [$a->getIssueDate()->toString(), $b->getSellerName(), null === $b->getInvoiceNumber(), (string) $b->getInvoiceNumber(), $b->getId()->toRfc4122()]);

        return $invoices;
    }

    public function add(PurchaseInvoice $invoice, ?PurchaseInvoiceDocument $document = null): void
    {
        if (null !== $document && $document->getPurchaseInvoice() !== $invoice) {
            throw new \InvalidArgumentException('The document belongs to another invoice.');
        }
        $externalId = $invoice->getExternalId();
        if (null !== $externalId && null !== $this->findByExternalId($invoice->getSource(), $externalId)) {
            throw PurchaseInvoiceAlreadyImportedException::for($invoice->getSource()->value, $externalId);
        }

        $this->byId[$invoice->getId()->toRfc4122()] = $invoice;
        if (null !== $document) {
            $this->documents[$invoice->getId()->toRfc4122()] = $document;
        }
    }

    public function save(PurchaseInvoice $invoice): void
    {
        ++$this->saveCallCount;
    }

    public function remove(PurchaseInvoice $invoice): void
    {
        unset($this->byId[$invoice->getId()->toRfc4122()], $this->documents[$invoice->getId()->toRfc4122()]);
    }

    public function findByInvoiceId(Uuid $invoiceId): ?PurchaseInvoiceDocument
    {
        return $this->documents[$invoiceId->toRfc4122()] ?? null;
    }

    public function findFormatsByInvoiceIds(array $invoiceIds): array
    {
        $formats = [];
        foreach ($invoiceIds as $invoiceId) {
            $document = $this->documents[$invoiceId->toRfc4122()] ?? null;
            if (null !== $document) {
                $formats[$invoiceId->toRfc4122()] = $document->getFormat();
            }
        }

        return $formats;
    }

    /** @return list<PurchaseInvoice> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
