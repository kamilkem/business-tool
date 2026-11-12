<?php

declare(strict_types=1);

namespace App\Expenses\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PurchaseInvoice> */
final class PurchaseInvoiceRepository extends ServiceEntityRepository implements PurchaseInvoiceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseInvoice::class);
    }

    public function findById(Uuid $id): ?PurchaseInvoice
    {
        return $this->find($id);
    }

    public function findByExternalId(PurchaseInvoiceSource $source, string $externalId): ?PurchaseInvoice
    {
        return $this->findOneBy(['source' => $source, 'externalId' => $externalId]);
    }

    public function add(PurchaseInvoice $invoice, ?PurchaseInvoiceDocument $document = null): void
    {
        if (null !== $document && $document->getPurchaseInvoice() !== $invoice) {
            throw new \InvalidArgumentException('The document belongs to another invoice.');
        }

        // One flush, so one transaction: the invoice is never stored without its document.
        $this->getEntityManager()->persist($invoice);
        if (null !== $document) {
            $this->getEntityManager()->persist($document);
        }

        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), PurchaseInvoice::ONE_PER_EXTERNAL_ID)) {
                throw $e;
            }

            throw PurchaseInvoiceAlreadyImportedException::for($invoice->getSource()->value, (string) $invoice->getExternalId());
        }
    }

    public function save(PurchaseInvoice $invoice): void
    {
        $this->getEntityManager()->flush();
    }
}
