<?php

declare(strict_types=1);

namespace App\CashFlow\Domain\Exception;

/**
 * The organization has one end-of-day close per business date (ADR 0033 §6). It says who closed the
 * date and when, so the person closing knows whom to ask; owners and managers correct that close in
 * the admin instead (#189).
 */
final class DayAlreadyClosedException extends CashFlowException
{
    public static function byMember(string $date, string $dayCloseId, string $closedBy, string $closedByFirstName, string $closedByLastName, \DateTimeImmutable $closedAt): self
    {
        return new self(
            \sprintf('%s is already closed, by %s %s at %s.', $date, $closedByFirstName, $closedByLastName, $closedAt->format(\DATE_ATOM)),
            [
                'date' => $date,
                'dayCloseId' => $dayCloseId,
                'closedBy' => $closedBy,
                'closedByFirstName' => $closedByFirstName,
                'closedByLastName' => $closedByLastName,
                'closedAt' => $closedAt->format(\DATE_ATOM),
            ],
            variant: __FUNCTION__,
        );
    }

    /** Two closes of the same date were saved at the same moment, and this one lost. */
    public static function concurrently(string $date): self
    {
        return new self(\sprintf('%s was closed by someone else at the same moment.', $date), ['date' => $date], variant: __FUNCTION__);
    }
}
