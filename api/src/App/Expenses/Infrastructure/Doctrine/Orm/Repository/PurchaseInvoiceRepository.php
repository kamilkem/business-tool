<?php

declare(strict_types=1);

namespace App\Expenses\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceItem;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceFilter;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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

    public function findStoredExternalIds(PurchaseInvoiceSource $source, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        /** @var list<string> $stored */
        $stored = $this->createQueryBuilder('i')
            ->select('i.externalId')
            ->where('i.source = :source')
            ->andWhere('i.externalId IN (:externalIds)')
            ->setParameter('source', $source)
            ->setParameter('externalIds', array_values(array_unique($externalIds)))
            ->getQuery()
            ->getSingleColumnResult();

        return $stored;
    }

    public function findImportedIdsWithDocumentAndNoItems(PurchaseInvoiceSource $source, ?Uuid $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where('i.source = :source')
            ->andWhere(\sprintf('EXISTS (SELECT IDENTITY(d.purchaseInvoice) FROM %s d WHERE d.purchaseInvoice = i)', PurchaseInvoiceDocument::class))
            ->andWhere(\sprintf('NOT EXISTS (SELECT it.ordinal FROM %s it WHERE it.purchaseInvoice = i)', PurchaseInvoiceItem::class))
            ->setParameter('source', $source)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit);
        if (null !== $after) {
            $qb->andWhere('i.id > :after')->setParameter('after', $after, UuidType::NAME);
        }

        /** @var list<Uuid|string> $ids */
        $ids = $qb->getQuery()->getSingleColumnResult();

        return array_map(static fn (Uuid|string $id): Uuid => $id instanceof Uuid ? $id : Uuid::fromString($id), $ids);
    }

    public function findWithItemsAfter(?Uuid $after, int $limit): array
    {
        // Two steps, as a fetch-joined collection cannot be limited: the page of ids, then the invoices with their items.
        $page = $this->createQueryBuilder('i')
            ->select('i.id')
            ->where(\sprintf('EXISTS (SELECT it.ordinal FROM %s it WHERE it.purchaseInvoice = i)', PurchaseInvoiceItem::class))
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit);
        if (null !== $after) {
            $page->andWhere('i.id > :after')->setParameter('after', $after, UuidType::NAME);
        }
        /** @var list<Uuid|string> $ids */
        $ids = $page->getQuery()->getSingleColumnResult();
        if ([] === $ids) {
            return [];
        }

        /** @var list<PurchaseInvoice> */
        return $this->createQueryBuilder('i')
            ->leftJoin('i.items', 'it')
            ->addSelect('it')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid|string $id): string => $id instanceof Uuid ? $id->toRfc4122() : $id, $ids))
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOrganizationIdsWithItems(): array
    {
        // Plain SQL on purpose: the TenantFilter applies to ORM queries only, and this list spans
        // organizations. It returns ids and nothing else.
        /** @var list<string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT DISTINCT organization_id FROM purchase_invoice_item ORDER BY organization_id');

        return $ids;
    }

    public function findInvoiceNumbers(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: Uuid|string, invoiceNumber: string}> $rows */
        $rows = $this->createQueryBuilder('i')
            ->select('i.id', 'i.invoiceNumber')
            ->where('i.id IN (:ids)')
            ->andWhere('i.invoiceNumber IS NOT NULL')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $numbers = [];
        foreach ($rows as $row) {
            $id = $row['id'] instanceof Uuid ? $row['id']->toRfc4122() : $row['id'];
            $numbers[$id] = $row['invoiceNumber'];
        }

        return $numbers;
    }

    public function findByFilter(PurchaseInvoiceFilter $filter): array
    {
        // The VAT lines are fetched with the invoices: the list shows them and adds them up (#53).
        $qb = $this->createQueryBuilder('i')
            ->leftJoin('i.vatLines', 'v')
            ->addSelect('v')
            ->where('i.issueDate >= :first')
            ->andWhere('i.issueDate <= :last')
            ->setParameter('first', $filter->period->first, BusinessDateType::NAME)
            ->setParameter('last', $filter->period->last, BusinessDateType::NAME)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.sellerName', 'ASC')
            ->addOrderBy('i.invoiceNumber', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        if (null !== $filter->seller) {
            $seller = $qb->expr()->orX("LOWER(i.sellerName) LIKE :sellerName ESCAPE '!'");
            $qb->setParameter('sellerName', '%'.self::escapeLike($filter->seller).'%');
            $taxId = $filter->sellerTaxId();
            if (null !== $taxId) {
                $seller->add("LOWER(i.sellerTaxId) LIKE :sellerTaxId ESCAPE '!'");
                $qb->setParameter('sellerTaxId', '%'.self::escapeLike($taxId).'%');
            }
            $qb->andWhere($seller);
        }

        /** @var list<PurchaseInvoice> */
        return $qb->getQuery()->getResult();
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

    public function remove(PurchaseInvoice $invoice): void
    {
        // The VAT lines go with it (cascade remove); a document, by the database's ON DELETE CASCADE.
        $this->getEntityManager()->remove($invoice);
        $this->getEntityManager()->flush();
    }

    /** Makes %, _ and the escape character itself literal in a LIKE pattern. */
    private static function escapeLike(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
