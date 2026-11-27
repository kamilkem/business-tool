<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Model\DecimalString;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Purchasing\Domain\Matching\MatchScope;
use App\Purchasing\Domain\Matching\MatchSource;
use App\Purchasing\Domain\Matching\ModelOutcomeCounts;
use App\Purchasing\Domain\Matching\PriceSanity;
use App\Purchasing\Domain\Model\PackageSize;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Purchasing\Domain\Model\PurchasedItemStatus;
use App\Purchasing\Domain\PackageSize\PackageSizeCount;
use App\Purchasing\Domain\Repository\PurchasedItemFilter;
use App\Purchasing\Domain\Proposal\ProposedItem;
use App\Purchasing\Domain\Repository\PurchasedItemRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
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

    public function findMatchableIds(MatchScope $scope, ?Uuid $afterId, int $limit, ?\DateTimeImmutable $askedBefore = null): array
    {
        $query = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.status <> :confirmed')
            ->andWhere('i.matchingSkipped = false')
            ->setParameter('confirmed', PurchasedItemStatus::Confirmed->value)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit);
        if (MatchScope::NotYetMatched === $scope) {
            $query->andWhere('i.matchedAt IS NULL');
        } elseif (MatchScope::Unassigned === $scope) {
            $query->andWhere('i.status = :unassigned')->setParameter('unassigned', PurchasedItemStatus::Unassigned->value);
        }
        if (null !== $askedBefore) {
            $query->andWhere('i.matchedAt IS NULL OR i.matchedAt < :askedBefore')->setParameter('askedBefore', $askedBefore, Types::DATETIME_IMMUTABLE);
        }
        if (null !== $afterId) {
            $query->andWhere('i.id > :after')->setParameter('after', $afterId, 'uuid');
        }

        /** @var list<array{id: Uuid}> $rows */
        $rows = $query->getQuery()->getResult();

        return array_map(static fn (array $row): Uuid => $row['id'], $rows);
    }

    public function countMatchable(MatchScope $scope): int
    {
        $query = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.status <> :confirmed')
            ->andWhere('i.matchingSkipped = false')
            ->setParameter('confirmed', PurchasedItemStatus::Confirmed->value);
        if (MatchScope::NotYetMatched === $scope) {
            $query->andWhere('i.matchedAt IS NULL');
        } elseif (MatchScope::Unassigned === $scope) {
            $query->andWhere('i.status = :unassigned')->setParameter('unassigned', PurchasedItemStatus::Unassigned->value);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    public function countModelOutcomesSince(\DateTimeImmutable $since): ModelOutcomeCounts
    {
        /** @var list<array{status: PurchasedItemStatus|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status', 'COUNT(i.id) AS total')
            ->where('i.matchedAt >= :since')
            ->andWhere('i.matchingSkipped = false')
            ->andWhere('i.status = :unassigned OR i.matchSource IN (:modelSources)')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('unassigned', PurchasedItemStatus::Unassigned->value)
            ->setParameter('modelSources', [MatchSource::Model->value, MatchSource::ProposedName->value], ArrayParameterType::STRING)
            ->groupBy('i.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status'] instanceof PurchasedItemStatus ? $row['status']->value : $row['status']] = (int) $row['total'];
        }

        return new ModelOutcomeCounts(
            array_sum($counts),
            $counts[PurchasedItemStatus::Assigned->value] ?? 0,
            $counts[PurchasedItemStatus::Suggested->value] ?? 0,
        );
    }

    public function findConfirmedIds(?Uuid $afterId, int $limit): array
    {
        $query = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.status = :confirmed')
            ->andWhere('i.product IS NOT NULL')
            ->setParameter('confirmed', PurchasedItemStatus::Confirmed->value)
            ->orderBy('i.invoiceIssueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        if (null !== $afterId) {
            $query->andWhere('(i.invoiceIssueDate < (SELECT c.invoiceIssueDate FROM '.PurchasedItem::class.' c WHERE c.id = :after) OR (i.invoiceIssueDate = (SELECT c2.invoiceIssueDate FROM '.PurchasedItem::class.' c2 WHERE c2.id = :after) AND i.id < :after))')
                ->setParameter('after', $afterId, 'uuid');
        }

        /** @var list<array{id: Uuid}> $rows */
        $rows = $query->getQuery()->getResult();

        return array_map(static fn (array $row): Uuid => $row['id'], $rows);
    }

    public function confirmedPricesPerBaseUnit(Uuid $productId, string $currency, int $limit): array
    {
        $corrections = array_map(
            static fn (PurchaseInvoiceKind $kind): string => $kind->value,
            array_filter(PurchaseInvoiceKind::cases(), static fn (PurchaseInvoiceKind $kind): bool => $kind->isCorrection()),
        );

        /** @var list<array{net: int|string|null, quantity: string|null, packageSize: string|null, bundleCount: int|null}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('COALESCE(i.netAmount, i.grossAmount - i.vatAmount) AS net', 'i.quantity AS quantity', 'i.packageSize AS packageSize', 'i.bundleCount AS bundleCount')
            ->where('i.product = :product')
            ->andWhere('i.status = :confirmed')
            ->andWhere('i.currency = :currency')
            ->andWhere('i.beforeCorrection = false')
            ->andWhere('i.invoiceKind NOT IN (:corrections)')
            ->andWhere('i.packageSize IS NOT NULL')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('confirmed', PurchasedItemStatus::Confirmed->value)
            ->setParameter('currency', $currency)
            ->setParameter('corrections', array_values($corrections), ArrayParameterType::STRING)
            ->orderBy('i.invoiceIssueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $prices = [];
        foreach ($rows as $row) {
            $price = PriceSanity::perBaseUnit(null === $row['net'] ? null : (int) $row['net'], DecimalString::trimmed($row['quantity']), self::sizePerUnit($row['packageSize'], $row['bundleCount']));
            if (null !== $price) {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    public function findProposed(): array
    {
        /** @var list<array{id: Uuid, name: string, proposedName: string, proposedNameKey: string, proposedMeasure: ProductMeasure, categoryId: string|null}> $rows */
        $rows = $this->proposing()
            ->select('i.id AS id', 'i.name AS name', 'i.proposedName AS proposedName', 'i.proposedNameKey AS proposedNameKey', 'i.proposedMeasure AS proposedMeasure', 'IDENTITY(i.proposedCategory) AS categoryId')
            ->orderBy('i.name', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): ProposedItem => new ProposedItem(
            $row['id']->toRfc4122(),
            $row['name'],
            $row['proposedName'],
            $row['proposedNameKey'],
            $row['proposedMeasure'],
            $row['categoryId'],
        ), $rows);
    }

    public function findByProposalKey(string $key): array
    {
        /** @var list<PurchasedItem> */
        return $this->proposing()
            ->andWhere('i.proposedNameKey = :key')
            ->setParameter('key', $key)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByProduct(Uuid $productId): array
    {
        /** @var list<PurchasedItem> */
        return $this->createQueryBuilder('i')
            ->where('i.product = :product')
            ->setParameter('product', $productId, 'uuid')
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPackageSizesOfProduct(Uuid $productId): array
    {
        /** @var list<array{unit: string|null, packageSize: string|null, bundleCount: int|null, confirmed: bool|int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.unit AS unit', 'i.packageSize AS packageSize', 'i.bundleCount AS bundleCount', '(CASE WHEN i.status = :confirmed THEN 1 ELSE 0 END) AS confirmed', 'COUNT(i.id) AS total')
            ->where('i.product = :product')
            ->setParameter('product', $productId, 'uuid')
            ->setParameter('confirmed', PurchasedItemStatus::Confirmed->value)
            ->groupBy('i.unit', 'i.packageSize', 'i.bundleCount', 'confirmed')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): PackageSizeCount => new PackageSizeCount($row['unit'], $row['packageSize'], null === $row['bundleCount'] ? null : (int) $row['bundleCount'], 1 === (int) $row['confirmed'], (int) $row['total']), $rows);
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

    /** The items that carry a proposal a person may still decide on: unassigned and open to matching. */
    private function proposing(): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->where('i.proposedNameKey IS NOT NULL')
            ->andWhere('i.status = :unassigned')
            ->andWhere('i.matchingSkipped = false')
            ->setParameter('unassigned', PurchasedItemStatus::Unassigned->value);
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

    /** The size per unit of the quantity of a stored package size and bundle count (ADR 0048); null when the size is unknown. */
    private static function sizePerUnit(?string $packageSize, ?int $bundleCount): ?string
    {
        $size = DecimalString::trimmed($packageSize);

        return null === $size ? null : PackageSize::of($size, $bundleCount)->total();
    }
}
