<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetProductPriceHistory;

use App\Expenses\Application\Query\GetPurchaseInvoiceNumbers\GetPurchaseInvoiceNumbersQuery;
use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Analysis\PricePoint;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\ProductRepositoryInterface;
use App\Purchasing\Domain\Repository\PurchaseAnalysisRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\TimeException;
use Symfony\Component\Uid\Uuid;

final readonly class GetProductPriceHistoryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private PurchaseAnalysisRepositoryInterface $analysis,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws TimeException                          when $from or $to is not a date
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     */
    public function __invoke(GetProductPriceHistoryQuery $query): ?ProductPriceHistoryView
    {
        $from = null === $query->from ? null : BusinessDate::fromString($query->from);
        $to = null === $query->to ? null : BusinessDate::fromString($query->to);

        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }
        $product = Uuid::isValid($query->productId) ? $this->products->findById(Uuid::fromString($query->productId)) : null;
        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($query->organizationId, $query->actorId));
        if (null === $product || null === $organization) {
            return null;
        }

        $points = $this->analysis->priceHistory($product->getId(), $organization->currency, $from, $to);
        $numbers = $this->invoiceNumbers($query->organizationId, $points);

        return new ProductPriceHistoryView(
            $product->getId()->toRfc4122(),
            $product->getName(),
            $product->getMeasure(),
            $product->getReferenceQuantity(),
            $organization->currency,
            $from?->toString(),
            $to?->toString(),
            array_map(static fn (PricePoint $point): PricePointView => new PricePointView($point, $numbers[$point->invoiceId] ?? null), $points),
        );
    }

    /**
     * Asks Expenses, through the bus, for the numbers of the points' invoices.
     *
     * @param list<PricePoint> $points
     *
     * @return array<string, string> the number by invoice id
     */
    private function invoiceNumbers(string $organizationId, array $points): array
    {
        $invoiceIds = array_values(array_unique(array_map(static fn (PricePoint $point): string => $point->invoiceId, $points)));

        $numbers = [];
        foreach (array_chunk($invoiceIds, GetPurchaseInvoiceNumbersQuery::MAX_INVOICES) as $chunk) {
            /** @var array<string, string> $found */
            $found = $this->queryBus->ask(new GetPurchaseInvoiceNumbersQuery($organizationId, $chunk));
            $numbers += $found;
        }

        return $numbers;
    }
}
