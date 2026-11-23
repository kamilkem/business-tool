<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** A month is closed only once it has ended in the organization's time zone (ADR 0034 §1). */
final class RevenueMonthNotEndedException extends IncomeException
{
    /**
     * @param string $month YYYY-MM
     * @param string $today YYYY-MM-DD in the organization's time zone
     */
    public static function of(string $month, string $today): self
    {
        return new self(\sprintf('The month %s has not ended yet (today is %s).', $month, $today), ['month' => $month, 'today' => $today]);
    }
}
