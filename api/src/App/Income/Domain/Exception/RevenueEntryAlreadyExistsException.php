<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** One entry per register and business date: a correction is an edit, not another entry (ADR 0022 §3). */
final class RevenueEntryAlreadyExistsException extends IncomeException
{
    public static function for(string $registerId, string $businessDate): self
    {
        return new self(\sprintf('Register "%s" already has an entry for %s.', $registerId, $businessDate), ['registerId' => $registerId, 'businessDate' => $businessDate]);
    }
}
