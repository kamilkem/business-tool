<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** An entry covers a day that has started: its business date is never after today (ADR 0022 §3). */
final class RevenueEntryInTheFutureException extends IncomeException
{
    public static function of(string $businessDate, string $today): self
    {
        return new self(\sprintf('The business date %s is after today (%s).', $businessDate, $today), ['businessDate' => $businessDate, 'today' => $today]);
    }
}
