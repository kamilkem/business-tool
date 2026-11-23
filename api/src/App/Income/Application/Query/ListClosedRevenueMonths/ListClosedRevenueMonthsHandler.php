<?php

declare(strict_types=1);

namespace App\Income\Application\Query\ListClosedRevenueMonths;

use App\Income\Domain\Model\RevenueMonthLocks;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class ListClosedRevenueMonthsHandler implements QueryHandlerInterface
{
    public function __construct(
        private RevenueMonthRepositoryInterface $months,
    ) {
    }

    public function __invoke(ListClosedRevenueMonthsQuery $query): RevenueMonthLocks
    {
        return $this->months->locks();
    }
}
