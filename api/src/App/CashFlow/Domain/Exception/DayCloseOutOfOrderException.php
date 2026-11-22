<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * A new close's date must be after the date of every existing close, so the ledger order (closes
 * sit at the moment they were made) matches the order of their dates (ADR 0033 §6). A day nobody
 * closed has its cash in the next close.
 */
final class DayCloseOutOfOrderException extends CashFlowException
{
    public static function of(string $date, string $lastClosedDate): self
    {
        return new self(
            \sprintf('%s cannot be closed any more: %s is already closed, and closes go in date order.', $date, $lastClosedDate),
            ['date' => $date, 'lastClosedDate' => $lastClosedDate],
        );
    }
}
