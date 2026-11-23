<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Reopening a month that is open (ADR 0034 §1), e.g. reopened by another owner meanwhile. */
final class RevenueMonthNotClosedException extends IncomeException
{
    /** @param string $month YYYY-MM */
    public static function of(string $month): self
    {
        return new self(\sprintf('The month %s is not closed.', $month), ['month' => $month]);
    }
}
