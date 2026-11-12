<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Application;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
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

    public function findByInvoiceId(Uuid $invoiceId): ?PurchaseInvoiceDocument
    {
        return $this->documents[$invoiceId->toRfc4122()] ?? null;
    }

    /** @return list<PurchaseInvoice> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
