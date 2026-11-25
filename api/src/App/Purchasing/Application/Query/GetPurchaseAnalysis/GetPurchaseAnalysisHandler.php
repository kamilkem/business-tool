<?php

declare(strict_types=1);

namespace App\Purchasing\Application\Query\GetPurchaseAnalysis;

use App\Organization\Application\Query\GetOrganization\GetOrganizationQuery;
use App\Organization\Application\Query\GetOrganization\OrganizationView;
use App\Purchasing\Application\PurchasingActor;
use App\Purchasing\Domain\Analysis\AnalysisAmounts;
use App\Purchasing\Domain\Analysis\ProductSpend;
use App\Purchasing\Domain\Exception\PurchaseAnalysisPeriodTooLongException;
use App\Purchasing\Domain\Exception\PurchasingManagementForbiddenException;
use App\Purchasing\Domain\Repository\PurchaseAnalysisRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Time\BusinessPeriod;
use App\Shared\Domain\Time\Exception\TimeException;

final readonly class GetPurchaseAnalysisHandler implements QueryHandlerInterface
{
    public function __construct(
        private PurchaseAnalysisRepositoryInterface $analysis,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @throws TimeException                          when $from or $to is not a date, or $to is before $from
     * @throws PurchaseAnalysisPeriodTooLongException when the period is longer than GetPurchaseAnalysisQuery::MAX_DAYS
     * @throws PurchasingManagementForbiddenException when the actor is an employee
     */
    public function __invoke(GetPurchaseAnalysisQuery $query): ?PurchaseAnalysisView
    {
        $period = BusinessPeriod::fromStrings($query->from, $query->to);
        if ($period->dayCount() > GetPurchaseAnalysisQuery::MAX_DAYS) {
            throw PurchaseAnalysisPeriodTooLongException::of($period, GetPurchaseAnalysisQuery::MAX_DAYS);
        }

        if (null === PurchasingActor::resolveOrNull($this->queryBus, $query->organizationId, $query->actorId)) {
            return null;
        }
        /** @var OrganizationView|null $organization */
        $organization = $this->queryBus->ask(new GetOrganizationQuery($query->organizationId, $query->actorId));
        if (null === $organization) {
            return null;
        }

        $analysis = $this->analysis->overview($period, $organization->currency);

        $uncategorized = array_filter($analysis->products, static fn (ProductSpend $product): bool => null === $product->categoryId);
        $total = new AnalysisAmounts(
            $analysis->notAssignedSpend->net + array_sum(array_map(static fn (ProductSpend $product): int => $product->spend->net, $analysis->products)),
            $analysis->notAssignedSpend->gross + array_sum(array_map(static fn (ProductSpend $product): int => $product->spend->gross, $analysis->products)),
        );

        return new PurchaseAnalysisView(
            $period->first->toString(),
            $period->last->toString(),
            $organization->currency,
            $analysis,
            $total,
            new AnalysisAmounts(
                array_sum(array_map(static fn (ProductSpend $product): int => $product->spend->net, $uncategorized)),
                array_sum(array_map(static fn (ProductSpend $product): int => $product->spend->gross, $uncategorized)),
            ),
            \count($uncategorized),
        );
    }
}
