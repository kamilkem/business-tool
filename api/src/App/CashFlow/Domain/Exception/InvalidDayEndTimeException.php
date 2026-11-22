<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/** The day-end time is a local time from 00:00 to 12:00, as HH:MM (ADR 0033 §5). */
final class InvalidDayEndTimeException extends CashFlowException
{
    public static function of(string $value, string $latest): self
    {
        return new self(\sprintf('The day-end time must be a time from 00:00 to %s (HH:MM), "%s" given.', $latest, $value), ['dayEndsAt' => $value, 'latest' => $latest]);
    }
}
