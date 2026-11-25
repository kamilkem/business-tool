<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetPurchasedItem;

use App\Expenses\Application\Query\GetPurchaseInvoiceNumbers\GetPurchaseInvoiceNumbersQuery;
use App\Purchasing\Domain\Model\PurchasedItem;
use App\Shared\Application\Query\QueryBusInterface;

/** Builds the views of purchased items, asking Expenses once for the numbers of their invoices (ADR 0041 §1: only through the bus). */
final readonly class PurchasedItemViews
{
    public function __construct(private QueryBusInterface $queryBus)
    {
    }

    /**
     * @param list<PurchasedItem> $items at most GetPurchaseInvoiceNumbersQuery::MAX_INVOICES
     *
     * @return list<PurchasedItemView> in the order of the items
     */
    public function of(string $organizationId, array $items): array
    {
        if ([] === $items) {
            return [];
        }

        /** @var array<string, string> $numbers */
        $numbers = $this->queryBus->ask(new GetPurchaseInvoiceNumbersQuery(
            $organizationId,
            array_values(array_unique(array_map(static fn (PurchasedItem $item): string => $item->getInvoiceId()->toRfc4122(), $items))),
        ));

        return array_map(
            static fn (PurchasedItem $item): PurchasedItemView => PurchasedItemView::fromItem($item, $numbers[$item->getInvoiceId()->toRfc4122()] ?? null),
            $items,
        );
    }
}
