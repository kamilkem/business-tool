<?php

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine\Orm\Repository;

use App\Expenses\Domain\Model\DecimalString;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Organization\Domain\TenantContextInterface;
use App\Purchasing\Domain\Analysis\AnalysisAmounts;
use App\Purchasing\Domain\Analysis\CategorySpend;
use App\Purchasing\Domain\Analysis\PriceChange;
use App\Purchasing\Domain\Analysis\PricePoint;
use App\Purchasing\Domain\Analysis\ProductSpend;
use App\Purchasing\Domain\Analysis\PurchaseAnalysisData;
use App\Purchasing\Domain\Model\ProductMeasure;
use App\Purchasing\Domain\Repository\PurchaseAnalysisRepositoryInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\BusinessPeriod;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The purchase analysis in plain SQL on Purchasing's tables (ADR 0041 §8): amounts, quantities and
 * prices are summed and divided in NUMERIC and rounded to minor units (PostgreSQL's ROUND: half away
 * from zero) only in the final SELECT. The ORM's TenantFilter does not apply to SQL, so every
 * statement filters by the current organization itself; with none selected, there is no analysis.
 *
 * Shared building blocks, as CTEs:
 *  - `item`: every item of the organization with its signed net and gross (ADR 0041 §6): the printed
 *    value, or the other one less or plus the VAT, null when neither can be derived; `sign` is -1 for a
 *    correction's "before" rows;
 *  - `point`: the price points (§7) with their prices per reference quantity;
 *  - `priced`: the points with the previous price on each basis, in the order issue date, when it was
 *    stored (the item's creation), invoice id, item index.
 */
final readonly class PurchaseAnalysisRepository implements PurchaseAnalysisRepositoryInterface
{
    private const string ORDER_OF_PURCHASE = 'issue_date, created_at, invoice_id, item_index';

    public function __construct(
        private Connection $connection,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function overview(BusinessPeriod $period, string $currency): PurchaseAnalysisData
    {
        $parameters = [
            'org' => $this->organizationId(),
            'currency' => $currency,
            'from' => $period->first->toString(),
            'to' => $period->last->toString(),
        ];

        return new PurchaseAnalysisData(
            $this->categories($parameters),
            $this->products($parameters),
            ...$this->buckets($parameters),
        );
    }

    public function priceHistory(Uuid $productId, string $currency, ?BusinessDate $from, ?BusinessDate $to): array
    {
        $sql = 'WITH '.self::itemCte().', '.self::pointCtes('AND p.id = CAST(:product AS uuid)').'
            SELECT priced.id, priced.issue_date, priced.seller_tax_id, priced.seller_name, priced.invoice_id, priced.name,
                   priced.quantity, priced.package_size,
                   '.self::priceColumns('priced.net_price', 'priced.prev_net_price', 'net').',
                   '.self::priceColumns('priced.gross_price', 'priced.prev_gross_price', 'gross').'
            FROM priced
            WHERE (CAST(:from AS date) IS NULL OR priced.issue_date >= CAST(:from AS date))
              AND (CAST(:to AS date) IS NULL OR priced.issue_date <= CAST(:to AS date))
            ORDER BY '.self::ORDER_OF_PURCHASE;

        $points = [];
        foreach ($this->connection->fetchAllAssociative($sql, [
            'org' => $this->organizationId(),
            'currency' => $currency,
            'product' => $productId->toRfc4122(),
            'from' => $from?->toString(),
            'to' => $to?->toString(),
        ]) as $row) {
            $points[] = new PricePoint(
                self::string($row['id']),
                self::string($row['issue_date']),
                null === $row['seller_tax_id'] ? null : self::string($row['seller_tax_id']),
                self::string($row['seller_name']),
                self::string($row['invoice_id']),
                self::string($row['name']),
                self::decimal($row['quantity']),
                self::decimal($row['package_size']),
                self::change($row, 'net'),
                self::change($row, 'gross'),
            );
        }

        return $points;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<CategorySpend>
     */
    private function categories(array $parameters): array
    {
        // `tree` pairs every category with itself and each descendant, whatever the depth.
        $sql = 'WITH RECURSIVE tree(ancestor_id, descendant_id) AS (
                SELECT c.id, c.id FROM product_category c WHERE c.organization_id = CAST(:org AS uuid)
                UNION ALL
                SELECT t.ancestor_id, c.id FROM tree t
                JOIN product_category c ON c.parent_id = t.descendant_id AND c.organization_id = CAST(:org AS uuid)
            ), '.self::itemCte().', '.self::productSpendCte().'
            SELECT c.id, c.name, c.parent_id,
                   COALESCE(SUM(ps.net) FILTER (WHERE t.descendant_id = c.id), 0) AS own_net,
                   COALESCE(SUM(ps.gross) FILTER (WHERE t.descendant_id = c.id), 0) AS own_gross,
                   COALESCE(SUM(ps.net), 0) AS total_net,
                   COALESCE(SUM(ps.gross), 0) AS total_gross
            FROM product_category c
            JOIN tree t ON t.ancestor_id = c.id
            LEFT JOIN product_spend ps ON ps.category_id = t.descendant_id
            WHERE c.organization_id = CAST(:org AS uuid)
            GROUP BY c.id, c.name, c.parent_id, c.name_key
            ORDER BY c.name_key, c.id';

        return array_map(
            static fn (array $row): CategorySpend => new CategorySpend(
                self::string($row['id']),
                self::string($row['name']),
                null === $row['parent_id'] ? null : self::string($row['parent_id']),
                new AnalysisAmounts(self::int($row['own_net']), self::int($row['own_gross'])),
                new AnalysisAmounts(self::int($row['total_net']), self::int($row['total_gross'])),
            ),
            $this->connection->fetchAllAssociative($sql, $parameters),
        );
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<ProductSpend>
     */
    private function products(array $parameters): array
    {
        $sql = 'WITH '.self::itemCte().', '.self::productSpendCte().', '.self::pointCtes('').',
            aggregate AS (
                SELECT product_id,
                       ROUND(SUM(base_quantity) / MAX(reference_quantity), 3) AS quantity,
                       ROUND(SUM(net) / NULLIF(SUM(base_quantity) FILTER (WHERE net IS NOT NULL), 0) * MAX(reference_quantity))::bigint AS average_net_price,
                       ROUND(SUM(gross) / NULLIF(SUM(base_quantity) FILTER (WHERE gross IS NOT NULL), 0) * MAX(reference_quantity))::bigint AS average_gross_price
                FROM priced
                WHERE issue_date BETWEEN CAST(:from AS date) AND CAST(:to AS date)
                GROUP BY product_id
            ), latest AS (
                SELECT DISTINCT ON (product_id) *
                FROM priced
                WHERE issue_date BETWEEN CAST(:from AS date) AND CAST(:to AS date)
                ORDER BY product_id, issue_date DESC, created_at DESC, invoice_id DESC, item_index DESC
            )
            SELECT ps.id, ps.name, ps.category_id, ps.measure, ps.reference_quantity, ps.net, ps.gross,
                   a.quantity, a.average_net_price, a.average_gross_price, l.issue_date AS last_price_date,
                   '.self::priceColumns('l.net_price', 'l.prev_net_price', 'net').',
                   '.self::priceColumns('l.gross_price', 'l.prev_gross_price', 'gross').'
            FROM product_spend ps
            LEFT JOIN aggregate a ON a.product_id = ps.id
            LEFT JOIN latest l ON l.product_id = ps.id
            ORDER BY ps.name_key, ps.id';

        return array_map(
            static fn (array $row): ProductSpend => new ProductSpend(
                self::string($row['id']),
                self::string($row['name']),
                null === $row['category_id'] ? null : self::string($row['category_id']),
                ProductMeasure::from(self::string($row['measure'])),
                self::nullableDecimal($row['reference_quantity']),
                new AnalysisAmounts(self::int($row['net']), self::int($row['gross'])),
                self::nullableDecimal($row['quantity']),
                null === $row['average_net_price'] ? null : self::int($row['average_net_price']),
                null === $row['average_gross_price'] ? null : self::int($row['average_gross_price']),
                null === $row['last_price_date'] ? null : self::string($row['last_price_date']),
                self::change($row, 'net'),
                self::change($row, 'gross'),
            ),
            $this->connection->fetchAllAssociative($sql, $parameters),
        );
    }

    /**
     * The items without a product and the counts of what the overview leaves out.
     *
     * @param array<string, string> $parameters
     *
     * @return array{AnalysisAmounts, int, int, int, int, int}
     */
    private function buckets(array $parameters): array
    {
        $sql = 'WITH '.self::itemCte().'
            SELECT COALESCE(SUM(i.sign * i.net) FILTER (WHERE i.product_id IS NULL AND i.currency = :currency), 0) AS not_assigned_net,
                   COALESCE(SUM(i.sign * i.gross) FILTER (WHERE i.product_id IS NULL AND i.currency = :currency), 0) AS not_assigned_gross,
                   COUNT(*) FILTER (WHERE i.product_id IS NULL AND i.currency = :currency) AS not_assigned_items,
                   COUNT(*) FILTER (WHERE i.currency <> :currency) AS other_currency_items,
                   COUNT(*) FILTER (WHERE i.currency = :currency AND i.net IS NULL) AS items_without_net,
                   COUNT(*) FILTER (WHERE i.currency = :currency AND i.gross IS NULL) AS items_without_gross,
                   COUNT(*) FILTER (WHERE i.currency = :currency AND p.measure <> \'none\' AND i.package_size IS NULL
                                    AND NOT i.before_correction AND i.invoice_kind NOT IN ('.self::correctionKinds().')) AS items_without_package_size
            FROM item i
            LEFT JOIN product p ON p.id = i.product_id
            WHERE i.issue_date BETWEEN CAST(:from AS date) AND CAST(:to AS date)
              AND (p.id IS NULL OR NOT p.excluded)';

        /** @var array<string, mixed> $row */
        $row = $this->connection->fetchAssociative($sql, $parameters);

        return [
            new AnalysisAmounts(self::int($row['not_assigned_net']), self::int($row['not_assigned_gross'])),
            self::int($row['not_assigned_items']),
            self::int($row['other_currency_items']),
            self::int($row['items_without_net']),
            self::int($row['items_without_gross']),
            self::int($row['items_without_package_size']),
        ];
    }

    private function organizationId(): string
    {
        return $this->tenantContext->getOrganization()->getId()->toRfc4122();
    }

    private static function itemCte(): string
    {
        return 'item AS (
                SELECT i.id, i.product_id, i.invoice_issue_date AS issue_date, i.invoice_id, i.item_index, i.created_at,
                       i.invoice_kind, i.before_correction, i.currency, i.name, i.seller_tax_id, i.seller_name,
                       i.quantity, i.package_size,
                       CASE WHEN i.before_correction THEN -1 ELSE 1 END AS sign,
                       COALESCE(i.net_amount, i.gross_amount - i.vat_amount) AS net,
                       COALESCE(i.gross_amount, i.net_amount + i.vat_amount) AS gross
                FROM purchased_item i
                WHERE i.organization_id = CAST(:org AS uuid)
            )';
    }

    /** The products that are not excluded with the spend of their items in the period (`:from`, `:to`) and currency. */
    private static function productSpendCte(): string
    {
        return 'product_spend AS (
                SELECT p.id, p.name, p.name_key, p.category_id, p.measure, p.reference_quantity,
                       COALESCE(SUM(i.sign * i.net), 0) AS net, COALESCE(SUM(i.sign * i.gross), 0) AS gross
                FROM item i
                JOIN product p ON p.id = i.product_id
                WHERE p.organization_id = CAST(:org AS uuid) AND NOT p.excluded
                  AND i.currency = :currency
                  AND i.issue_date BETWEEN CAST(:from AS date) AND CAST(:to AS date)
                GROUP BY p.id
            )';
    }

    /**
     * `point` and `priced`. $productFilter narrows the points by the product alias `p`.
     */
    private static function pointCtes(string $productFilter): string
    {
        $order = self::ORDER_OF_PURCHASE;

        return 'point AS (
                SELECT i.id, i.product_id, i.issue_date, i.created_at, i.invoice_id, i.item_index, i.name, i.seller_tax_id,
                       i.seller_name, i.quantity, i.package_size, i.net, i.gross, p.reference_quantity,
                       i.quantity * i.package_size AS base_quantity,
                       i.net / (i.quantity * i.package_size) * p.reference_quantity AS net_price,
                       i.gross / (i.quantity * i.package_size) * p.reference_quantity AS gross_price
                FROM item i
                JOIN product p ON p.id = i.product_id
                WHERE p.organization_id = CAST(:org AS uuid) AND p.measure <> \'none\' AND NOT p.excluded '.$productFilter.'
                  AND i.currency = :currency AND NOT i.before_correction AND i.invoice_kind NOT IN ('.self::correctionKinds().')
                  AND i.quantity > 0 AND i.package_size > 0
            ), net_before AS (
                SELECT id, LAG(net_price) OVER (PARTITION BY product_id ORDER BY '.$order.') AS prev
                FROM point WHERE net_price IS NOT NULL
            ), gross_before AS (
                SELECT id, LAG(gross_price) OVER (PARTITION BY product_id ORDER BY '.$order.') AS prev
                FROM point WHERE gross_price IS NOT NULL
            ), priced AS (
                SELECT point.*, net_before.prev AS prev_net_price, gross_before.prev AS prev_gross_price
                FROM point
                LEFT JOIN net_before ON net_before.id = point.id
                LEFT JOIN gross_before ON gross_before.id = point.id
            )';
    }

    /** The columns `<basis>_price`, `<basis>_change` and `<basis>_percent` of a price and its previous one. */
    private static function priceColumns(string $price, string $previous, string $basis): string
    {
        return \sprintf(
            'ROUND(%1$s)::bigint AS %3$s_price,
                   ROUND(%1$s - %2$s)::bigint AS %3$s_change,
                   ROUND((%1$s - %2$s) / NULLIF(%2$s, 0) * 100, 2)::text AS %3$s_percent',
            $price,
            $previous,
            $basis,
        );
    }

    /** The quoted values of the invoice kinds that correct another invoice. */
    private static function correctionKinds(): string
    {
        return implode(', ', array_map(
            static fn (PurchaseInvoiceKind $kind): string => "'".$kind->value."'",
            array_filter(PurchaseInvoiceKind::cases(), static fn (PurchaseInvoiceKind $kind): bool => $kind->isCorrection()),
        ));
    }

    /** @param array<string, mixed> $row */
    private static function change(array $row, string $basis): ?PriceChange
    {
        if (null === $row[$basis.'_price']) {
            return null;
        }

        return new PriceChange(
            self::int($row[$basis.'_price']),
            null === $row[$basis.'_change'] ? null : self::int($row[$basis.'_change']),
            null === $row[$basis.'_percent'] ? null : self::decimal($row[$basis.'_percent']),
        );
    }

    private static function string(mixed $value): string
    {
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        \assert(\is_string($value) || \is_int($value));

        return (string) $value;
    }

    private static function int(mixed $value): int
    {
        \assert(\is_int($value) || \is_string($value));

        return (int) $value;
    }

    private static function decimal(mixed $value): string
    {
        return DecimalString::trimmed(self::string($value)) ?? throw new \LogicException('A decimal was expected.');
    }

    private static function nullableDecimal(mixed $value): ?string
    {
        return null === $value ? null : self::decimal($value);
    }
}
