<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\Repository\PurchasedItemFilter;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
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

    public function findById(Uuid $id): ?PurchasedItem
    {
        return $this->find($id);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<PurchasedItem> */
        return $this->createQueryBuilder('i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();
    }

    public function search(PurchasedItemFilter $filter, int $offset, int $limit): array
    {
        $query = $this->filtered($filter)
            ->leftJoin('i.product', 'p')
            ->addSelect('p')
            ->orderBy('i.invoiceIssueDate', 'DESC')
            ->addOrderBy('i.sellerName', 'ASC')
            ->addOrderBy('i.invoiceId', 'ASC')
            ->addOrderBy('i.itemIndex', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        /** @var list<PurchasedItem> */
        return $query->getQuery()->getResult();
    }

    public function countMatching(PurchasedItemFilter $filter): int
    {
        return (int) $this->filtered($filter)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
    }

    public function save(PurchasedItem $item): void
    {
        $this->getEntityManager()->flush();
    }

    public function saveAll(array $items): void
    {
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

    private function filtered(PurchasedItemFilter $filter): QueryBuilder
    {
        $query = $this->createQueryBuilder('i');

        if ([] !== $filter->statuses) {
            $query->andWhere('i.status IN (:statuses)')
                ->setParameter('statuses', array_map(static fn (PurchasedItemStatus $status): string => $status->value, $filter->statuses), ArrayParameterType::STRING);
        }
        if (null !== $filter->productId) {
            $query->andWhere('i.product = :product')->setParameter('product', $filter->productId, 'uuid');
        }
        if (null !== $filter->from) {
            $query->andWhere('i.invoiceIssueDate >= :from')->setParameter('from', $filter->from, BusinessDateType::NAME);
        }
        if (null !== $filter->to) {
            $query->andWhere('i.invoiceIssueDate <= :to')->setParameter('to', $filter->to, BusinessDateType::NAME);
        }
        $needle = null === $filter->nameContains ? '' : mb_strtolower(trim($filter->nameContains));
        if ('' !== $needle) {
            $query->andWhere("LOWER(i.name) LIKE :needle ESCAPE '!'")
                ->setParameter('needle', '%'.strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']).'%');
        }

        return $query;
    }
}
