<?php

declare(strict_types=1);

namespace App\Income\Domain\Repository;

use App\Income\Domain\Exception\RevenueMonthAlreadyClosedException;
use App\Income\Domain\Model\RevenueMonth;
use App\Income\Domain\Model\RevenueMonthLocks;
use App\Shared\Domain\Time\BusinessPeriod;

interface RevenueMonthRepositoryInterface
{
    /** The organization's month, if it was ever closed. */
    public function findByMonth(BusinessPeriod $month): ?RevenueMonth;

    /** The organization's closed months (ADR 0034 §2). */
    public function locks(): RevenueMonthLocks;

    /**
     * @throws RevenueMonthAlreadyClosedException when another request closed the month first (the unique constraint caught it)
     */
    public function add(RevenueMonth $month): void;

    /** Persists changes made to an already managed month, its new actions included. */
    public function save(RevenueMonth $month): void;
}
