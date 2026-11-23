<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** Closing a month that is closed already (ADR 0034 §1), e.g. by another owner meanwhile. */
final class RevenueMonthAlreadyClosedException extends IncomeException
{
    /** @param string $month YYYY-MM */
    public static function of(string $month): self
    {
        return new self(\sprintf('The month %s is closed already.', $month), ['month' => $month]);
    }
}
