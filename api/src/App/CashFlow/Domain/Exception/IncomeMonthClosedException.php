<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * Income would have to book something in a month an owner closed (ADR 0034 §3), so Cash flow refuses
 * the write up front: a close dated in that month, or a write that would change the cash income of
 * such a close through its recalculation (ADR 0033 §7).
 */
final class IncomeMonthClosedException extends CashFlowException
{
    /**
     * @param string $businessDate YYYY-MM-DD
     * @param string $month        YYYY-MM
     */
    public static function forDate(string $businessDate, string $month): self
    {
        return new self(
            \sprintf('The Income month %s is closed: no end-of-day close of %s can be recorded, corrected or deleted until an owner reopens it.', $month, $businessDate),
            ['businessDate' => $businessDate, 'month' => $month],
            variant: __FUNCTION__,
        );
    }

    /**
     * @param string $businessDate YYYY-MM-DD, the recalculated close's
     * @param string $month        YYYY-MM
     */
    public static function recalculatedClose(string $dayCloseId, string $businessDate, string $month): self
    {
        return new self(
            \sprintf('This would change the cash income of the end-of-day close of %s, and the Income month %s is closed.', $businessDate, $month),
            ['dayCloseId' => $dayCloseId, 'businessDate' => $businessDate, 'month' => $month],
            variant: __FUNCTION__,
        );
    }
}
