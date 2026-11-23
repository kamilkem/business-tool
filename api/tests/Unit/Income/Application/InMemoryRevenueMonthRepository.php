<?php

declare(strict_types=1);

namespace App\Tests\Unit\Income\Application;

use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Model\RevenueMonth;
use App\Income\Domain\Model\RevenueMonthLocks;
use App\Income\Domain\Repository\RevenueMonthRepositoryInterface;
use App\Shared\Domain\Time\BusinessPeriod;

/** Months of one organization, as the TenantFilter would show them. */
final class InMemoryRevenueMonthRepository implements RevenueMonthRepositoryInterface
{
    /** @var array<string, RevenueMonth> by YYYY-MM */
    private array $months = [];

    public function findByMonth(BusinessPeriod $month): ?RevenueMonth
    {
        return $this->months[substr($month->first->toString(), 0, 7)] ?? null;
    }

    public function locks(): RevenueMonthLocks
    {
        return new RevenueMonthLocks(array_values(array_map(
            static fn (RevenueMonth $month): string => $month->monthString(),
            array_filter($this->months, static fn (RevenueMonth $month): bool => $month->isClosed()),
        )));
    }

    public function add(RevenueMonth $month): void
    {
        if (isset($this->months[$month->monthString()])) {
            throw RevenueMonthAlreadyClosedException::of($month->monthString());
        }
        $this->months[$month->monthString()] = $month;
    }

    public function save(RevenueMonth $month): void
    {
    }
}
