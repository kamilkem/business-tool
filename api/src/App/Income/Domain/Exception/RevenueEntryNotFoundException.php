<?php

declare(strict_types=1);

namespace App\Income\Domain\Exception;

/** No such revenue entry in this organization (another organization's is not found either). */
final class RevenueEntryNotFoundException extends IncomeException
{
    public static function withId(string $entryId): self
    {
        return new self(\sprintf('Revenue entry "%s" not found.', $entryId));
    }
}
