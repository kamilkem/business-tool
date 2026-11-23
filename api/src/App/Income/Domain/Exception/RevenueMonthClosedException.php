<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** A closed month locks its revenue entries (ADR 0034 §2): nothing is recorded, changed, booked or deleted in it until an owner reopens it. */
final class RevenueMonthClosedException extends IncomeException
{
    /**
     * @param string $month        YYYY-MM
     * @param string $businessDate YYYY-MM-DD, the entry's
     */
    public static function of(string $month, string $businessDate): self
    {
        return new self(
            \sprintf('The month %s is closed: revenue entries of %s cannot change until an owner reopens it.', $month, $businessDate),
            ['month' => $month, 'businessDate' => $businessDate],
        );
    }
}
