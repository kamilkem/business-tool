<?php

declare(strict_types=1);

namespace App\Expenses\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Repository\PurchaseInvoiceFilter;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\Type\BusinessDateType;
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
