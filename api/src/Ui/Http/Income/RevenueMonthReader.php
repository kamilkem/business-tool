<?php

declare(strict_types=1);

namespace Ui\Http\Income;

use App\Income\Application\Query\GetRevenueMonth\GetRevenueMonthQuery;
use App\Income\Application\Query\GetRevenueMonth\RevenueMonthView;
use App\Organization\Domain\Exception\OrganizationNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;

/** Answers a revenue month endpoint with the month as the query bus sees it. */
final readonly class RevenueMonthReader
{
    public function __construct(
        private QueryBusInterface $queryBus,
    ) {
    }

    public function read(string $organizationId, string $actorId, string $month): RevenueMonthResource
    {
        /** @var RevenueMonthView|null $view */
        $view = $this->queryBus->ask(new GetRevenueMonthQuery($organizationId, $actorId, $month));

        return RevenueMonthResource::fromView($view ?? throw OrganizationNotFoundException::forId($organizationId));
    }
}
