<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/**
 * An entry booked from an end-of-day close belongs to it (ADR 0033 §9): its payment lines change only
 * through the close, and it is not deleted by hand. Its VAT breakdown and note stay editable.
 */
final class RevenueEntryBookedByDayCloseException extends IncomeException
{
    public static function of(string $entryId, string $dayCloseId): self
    {
        return new self(
            \sprintf('The revenue entry "%s" was booked from the end-of-day close "%s": its payment lines change only through the close, and it is not deleted by hand.', $entryId, $dayCloseId),
            ['entryId' => $entryId, 'dayCloseId' => $dayCloseId],
        );
    }
}
