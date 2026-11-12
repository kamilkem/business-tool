<?php

declare(strict_types=1);

namespace App\Expenses\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Repository\PurchaseInvoiceDocumentRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PurchaseInvoiceDocument> */
final class PurchaseInvoiceDocumentRepository extends ServiceEntityRepository implements PurchaseInvoiceDocumentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseInvoiceDocument::class);
    }

    public function findByInvoiceId(Uuid $invoiceId): ?PurchaseInvoiceDocument
    {
        /** @var ?PurchaseInvoiceDocument */
        return $this->createQueryBuilder('d')
            ->where('IDENTITY(d.purchaseInvoice) = :invoiceId')
            ->setParameter('invoiceId', $invoiceId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
