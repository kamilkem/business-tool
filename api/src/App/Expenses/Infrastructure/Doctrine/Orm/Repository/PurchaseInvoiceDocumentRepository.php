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

    public function findFormatsByInvoiceIds(array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }

        /** @var list<array{invoiceId: Uuid, format: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.purchaseInvoice) AS invoiceId', 'd.format AS format')
            ->where('d.purchaseInvoice IN (:invoiceIds)')
            ->setParameter('invoiceIds', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $invoiceIds))
            ->getQuery()
            ->getArrayResult();

        $formats = [];
        foreach ($rows as $row) {
            $formats[Uuid::fromString((string) $row['invoiceId'])->toRfc4122()] = $row['format'];
        }

        return $formats;
    }
}
