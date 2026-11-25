<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<PurchasedItem> */
final class PurchasedItemRepository extends ServiceEntityRepository implements PurchasedItemRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchasedItem::class);
    }

    public function findByInvoice(Uuid $invoiceId): array
    {
        /** @var list<PurchasedItem> */
        return $this->createQueryBuilder('i')
            ->where('i.invoiceId = :invoice')
            ->setParameter('invoice', $invoiceId, 'uuid')
            ->orderBy('i.itemIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function addAll(array $items): void
    {
        if ([] === $items) {
            return;
        }

        foreach ($items as $item) {
            $this->getEntityManager()->persist($item);
        }
        $this->getEntityManager()->flush();
    }
}
