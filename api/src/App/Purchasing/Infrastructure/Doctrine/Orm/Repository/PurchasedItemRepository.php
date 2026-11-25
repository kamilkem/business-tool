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

    public function hasItemsOfProduct(Uuid $productId): bool
    {
        return null !== $this->findOneBy(['product' => $productId]);
    }

    public function moveItems(Uuid $fromProductId, Uuid $toProductId): void
    {
        // A bulk update: an organization's items run to many thousands, and nothing is loaded into the unit of work.
        $this->getEntityManager()->createQueryBuilder()
            ->update(PurchasedItem::class, 'i')
            ->set('i.product', ':to')
            ->where('i.product = :from')
            ->setParameter('to', $toProductId, 'uuid')
            ->setParameter('from', $fromProductId, 'uuid')
            ->getQuery()
            ->execute();
    }
}
